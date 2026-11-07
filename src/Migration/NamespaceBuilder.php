<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

use DateInterval;
use Google\Protobuf\Duration;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Temporal\Api\Enums\V1\NamespaceState;
use Temporal\Api\Operatorservice\V1\DeleteNamespaceRequest;
use Temporal\Api\PBNamespace\V1\NamespaceConfig;
use Temporal\Api\PBNamespace\V1\UpdateNamespaceInfo;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceRequest;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceResponse;
use Temporal\Api\Workflowservice\V1\RegisterNamespaceRequest;
use Temporal\Api\Workflowservice\V1\UpdateNamespaceRequest;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final class NamespaceBuilder
{
    private ?string $description = null;
    private ?string $ownerEmail  = null;
    /** @var array<string, string> */
    private array $data     = [];
    private ?int $retention = null;
    private bool $pending   = false;

    public function __construct(
        private readonly MigrationRpcContext $rpc,
        private readonly string $namespace,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    public function description(string $description): self
    {
        if (trim($description) === '') {
            throw MigrationException::emptyNamespaceDescription();
        }

        $this->description = $description;
        $this->pending     = true;

        return $this;
    }

    public function ownerEmail(string $ownerEmail): self
    {
        if (trim($ownerEmail) === '') {
            throw MigrationException::emptyNamespaceOwnerEmail();
        }

        $this->ownerEmail = $ownerEmail;
        $this->pending    = true;

        return $this;
    }

    /** @param array<string, string> $data Declared keys are merged; omitted keys remain unmanaged. */
    public function data(array $data): self
    {
        // Validate through protobuf before retaining settings, including calls from untyped PHP.
        (new UpdateNamespaceInfo())->setData($data);
        $this->data    = array_replace($this->data, $data);
        $this->pending = $this->pending || $data !== [];

        return $this;
    }

    public function retention(DateInterval $retention): self
    {
        $seconds = $retention->d * 86400 + $retention->h * 3600 + $retention->i * 60 + $retention->s;

        if ($retention->invert !== 0
            || $retention->y !== 0
            || $retention->m !== 0
            || $retention->f !== 0.0
            || $seconds <= 0
        ) {
            throw MigrationException::invalidRetention();
        }

        $this->retention = $seconds;
        $this->pending   = true;

        return $this;
    }

    public function ensure(): void
    {
        $this->logger->debug('Ensuring Temporal namespace', ['namespace' => $this->namespace, 'retentionSeconds' => $this->retention]);
        $state = $this->describe();

        if ($state === null) {
            $this->register();
        }

        $updated = false;
        $this->rpc->observe(function () use (&$updated): bool {
            $state = $this->describe();

            if ($state === null) {
                return false;
            }

            if ($state->getNamespaceInfo()?->getState() !== NamespaceState::NAMESPACE_STATE_REGISTERED) {
                throw MigrationException::namespaceNotRegistered($this->namespace);
            }

            if ($this->matches($state)) {
                return true;
            }

            if (!$updated) {
                $request = (new UpdateNamespaceRequest())->setNamespace($this->namespace);

                if ($this->retention !== null) {
                    $request->setConfig((new NamespaceConfig())->setWorkflowExecutionRetentionTtl($this->duration()));
                }

                if ($this->description !== null || $this->ownerEmail !== null || $this->data !== []) {
                    $info = new UpdateNamespaceInfo();

                    if ($this->description !== null) {
                        $info->setDescription($this->description);
                    }

                    if ($this->ownerEmail !== null) {
                        $info->setOwnerEmail($this->ownerEmail);
                    }

                    $request->setUpdateInfo($info->setData($this->data));
                }

                try {
                    $this->rpc->serviceClient()->UpdateNamespace($request);
                } catch (ServiceClientException $exception) {
                    // Describe reads persistence before the namespace cache used by Update catches up.
                    if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                        throw $exception;
                    }

                    $this->logger->debug('Waiting for Temporal namespace update visibility', ['namespace' => $this->namespace]);

                    return false;
                }
                $updated = true;
                $this->logger->info('Updated declared Temporal namespace settings', ['namespace' => $this->namespace]);
            }

            return false;
        }, 'namespace readiness: ' . $this->namespace, 60, 1.0);
        $this->rpc->markEnsured($this->namespace);
        $this->pending = false;
        $this->logger->debug('Verified declared Temporal namespace settings', ['namespace' => $this->namespace]);
    }

    public function delete(): void
    {
        if ($this->pending) {
            throw MigrationException::cannotDeleteWithPendingSettings();
        }

        $this->rpc->observe(function (): bool {
            if ($this->describe() === null) {
                return true;
            }

            try {
                $request = (new DeleteNamespaceRequest())->setNamespace($this->namespace);
                $this->rpc->operatorClient()->DeleteNamespace($request);
            } catch (ServiceClientException $exception) {
                if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                    throw $exception;
                }

                $this->logger->debug('Waiting for Temporal namespace deletion visibility', ['namespace' => $this->namespace]);

                return false;
            }

            $this->logger->info('Requested Temporal namespace deletion', ['namespace' => $this->namespace]);

            return true;
        }, 'namespace deletion request: ' . $this->namespace, 60, 1.0);
    }

    /** @internal */
    public function assertApplied(): void
    {
        if ($this->pending) {
            throw MigrationException::unappliedNamespaceSettings($this->namespace);
        }
    }

    private function register(): void
    {
        if ($this->retention === null) {
            throw MigrationException::retentionRequired($this->namespace);
        }

        $request = (new RegisterNamespaceRequest())->setNamespace($this->namespace)
            ->setWorkflowExecutionRetentionPeriod($this->duration())
        ;

        if ($this->description !== null) {
            $request->setDescription($this->description);
        }

        if ($this->ownerEmail !== null) {
            $request->setOwnerEmail($this->ownerEmail);
        }

        $request->setData($this->data);

        try {
            $this->rpc->serviceClient()->RegisterNamespace($request);
            $this->logger->info('Created Temporal namespace', ['namespace' => $this->namespace]);
        } catch (ServiceClientException $exception) {
            if ($exception->getCode() !== StatusCode::ALREADY_EXISTS) {
                throw $exception;
            }
        }
    }

    private function matches(DescribeNamespaceResponse $state): bool
    {
        $actual = $state->getConfig()?->getWorkflowExecutionRetentionTtl();

        if ($this->retention !== null && (
            $actual === null
            || (int) $actual->getSeconds() !== $this->retention
            || $actual->getNanos() !== 0
        )) {
            return false;
        }

        $info = $state->getNamespaceInfo();

        if (($this->description !== null && $info?->getDescription() !== $this->description)
            || ($this->ownerEmail !== null && $info?->getOwnerEmail() !== $this->ownerEmail)
        ) {
            return false;
        }

        $actualData = $info === null ? [] : iterator_to_array($info->getData());

        foreach ($this->data as $key => $value) {
            if (!array_key_exists($key, $actualData) || $actualData[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function describe(): ?DescribeNamespaceResponse
    {
        try {
            $request = (new DescribeNamespaceRequest())->setNamespace($this->namespace);

            return $this->rpc->serviceClient()->DescribeNamespace($request);
        } catch (ServiceClientException $exception) {
            if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                throw $exception;
            }

            return null;
        }
    }

    private function duration(): Duration
    {
        assert($this->retention !== null);

        return (new Duration())->setSeconds($this->retention);
    }
}
