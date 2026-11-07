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
use Temporal\Client\GRPC\OperatorClientInterface as OperatorClient;
use Temporal\Client\GRPC\ServiceClientInterface as ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final class Scheme
{
    /** @var list<NamespaceBuilder|SearchAttributeBuilder> */
    private array $builders = [];

    public function __construct(
        private readonly MigrationRpcContext $rpc,
        private readonly string $defaultNamespace,
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    public function namespace(?string $name = null): NamespaceBuilder
    {
        $builder          = new NamespaceBuilder($this->rpc, $this->resolveNamespace($name), $this->logger);
        $this->builders[] = $builder;

        return $builder;
    }

    public function searchAttributes(?string $namespace = null): SearchAttributeBuilder
    {
        $builder          = new SearchAttributeBuilder($this->rpc, $this->resolveNamespace($namespace), $this->logger);
        $this->builders[] = $builder;

        return $builder;
    }

    public function operatorClient(): OperatorClient
    {
        return $this->rpc->operatorClient();
    }

    public function serviceClient(): ServiceClient
    {
        return $this->rpc->serviceClient();
    }

    /** @internal */
    public function assertApplied(): void
    {
        foreach ($this->builders as $builder) {
            $builder->assertApplied();
        }
    }

    private function resolveNamespace(?string $namespace): string
    {
        $namespace ??= $this->defaultNamespace;

        if (trim($namespace) === '' || str_contains($namespace, "\0")) {
            throw MigrationException::invalidNamespace();
        }

        return $namespace;
    }
}
