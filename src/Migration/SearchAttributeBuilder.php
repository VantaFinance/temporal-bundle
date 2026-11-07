<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesResponse;
use Temporal\Api\Operatorservice\V1\RemoveSearchAttributesRequest;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final class SearchAttributeBuilder
{
    /** @var array<string, int|null> */
    private array $operations = [];

    public function __construct(
        private readonly MigrationRpcContext $rpc,
        private readonly string $namespace,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    public function text(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_TEXT);
    }

    public function keyword(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD);
    }

    public function keywordList(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD_LIST);
    }

    public function integer(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_INT);
    }

    public function double(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_DOUBLE);
    }

    public function boolean(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_BOOL);
    }

    public function datetime(string $name): self
    {
        return $this->queue($name, IndexedValueType::INDEXED_VALUE_TYPE_DATETIME);
    }

    public function remove(string $name): self
    {
        return $this->queue($name, null);
    }

    public function apply(): void
    {
        if ($this->operations === []) {
            return;
        }

        $this->logger->debug('Applying Temporal search attributes', ['namespace' => $this->namespace, 'count' => count($this->operations)]);
        $state = $this->read();

        // Validate the entire batch before performing its first mutation.
        foreach ($this->operations as $name => $type) {
            $this->validateState((string) $name, $type, $state);
        }

        foreach ($this->operations as $name => $type) {
            $this->applyOperation((string) $name, $type);
        }

        $this->logger->info('Applied Temporal search attributes', ['namespace' => $this->namespace, 'count' => count($this->operations)]);
        $this->operations = [];
    }

    /** @internal */
    public function assertApplied(): void
    {
        if ($this->operations !== []) {
            throw MigrationException::unappliedSearchAttributes($this->namespace);
        }
    }

    private function queue(string $name, ?int $type): self
    {
        if (trim($name) === ''
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
            || str_starts_with($name, 'Temporal')
        ) {
            throw MigrationException::invalidSearchAttributeName($name);
        }

        if (array_key_exists($name, $this->operations) && $this->operations[$name] !== $type) {
            throw MigrationException::contradictorySearchAttributeOperations($name);
        }

        $this->operations[$name] = $type;

        return $this;
    }

    private function validateState(string $name, ?int $type, ListSearchAttributesResponse $state): void
    {
        if (isset($state->getSystemAttributes()[$name])) {
            throw MigrationException::systemSearchAttribute($name);
        }

        $existing = $this->attributeType($state->getCustomAttributes()[$name] ?? null);

        if ($type !== null && $existing !== null && $existing !== $type) {
            throw MigrationException::searchAttributeTypeConflict($name, $existing, $type);
        }
    }

    private function applyOperation(string $name, ?int $type): void
    {
        $state = $this->read();
        $this->validateState($name, $type, $state);
        $existing = $this->attributeType($state->getCustomAttributes()[$name] ?? null);

        if ($existing === $type) {
            return;
        }

        $this->logger->debug('Mutating Temporal search attribute', ['namespace' => $this->namespace, 'attribute' => $name, 'type' => $type]);

        try {
            if ($type === null) {
                $request = (new RemoveSearchAttributesRequest())->setNamespace($this->namespace)->setSearchAttributes([$name]);
                $this->rpc->operatorClient()->RemoveSearchAttributes($request);
            } else {
                $request = (new AddSearchAttributesRequest())->setNamespace($this->namespace)->setSearchAttributes([$name => $type]);
                $this->rpc->operatorClient()->AddSearchAttributes($request);
            }
        } catch (ServiceClientException $exception) {
            $race = $type === null ? StatusCode::NOT_FOUND : StatusCode::ALREADY_EXISTS;

            if ($exception->getCode() !== $race) {
                $this->logger->error('Temporal search attribute mutation failed', ['namespace' => $this->namespace, 'attribute' => $name, 'code' => $exception->getCode()]);
                throw $exception;
            }
        }

        $this->rpc->observe(function () use ($name, $type): bool {
            $state = $this->read();
            $this->validateState($name, $type, $state);

            return ($this->attributeType($state->getCustomAttributes()[$name] ?? null)) === $type;
        }, sprintf('search attribute "%s" in namespace "%s"', $name, $this->namespace), 10, 0.1);
    }

    private function attributeType(mixed $value): ?int
    {
        if ($value !== null && !is_int($value)) {
            throw MigrationException::invalidSearchAttributeType();
        }

        return $value;
    }

    private function read(): ListSearchAttributesResponse
    {
        $request  = (new ListSearchAttributesRequest())->setNamespace($this->namespace);
        $response = null;
        $this->rpc->observe(function () use ($request, &$response): bool {
            try {
                $response = $this->rpc->operatorClient()->ListSearchAttributes($request);

                return true;
            } catch (ServiceClientException $exception) {
                if ($exception->getCode() !== StatusCode::NOT_FOUND || !$this->rpc->isEnsured($this->namespace)) {
                    throw $exception;
                }

                return false;
            }
        }, 'namespace visibility: ' . $this->namespace, 60, 1.0);
        assert($response instanceof ListSearchAttributesResponse);

        return $response;
    }
}
