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
use Throwable;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final readonly class MigrationRunner
{
    public function __construct(
        private MigrationDiscovery $discovery,
        private ServiceClient $service,
        private OperatorClient $operator,
        private string $namespace,
        private int $timeout = 60,
        private Logger $logger = new NullLogger(),
        private string $client = 'default',
    ) {
    }

    /** @param list<MigrationDescriptor>|null $catalog */
    public function run(string $direction = 'up', ?array $catalog = null): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            throw MigrationException::invalidDirection();
        }

        $catalog ??= $this->discovery->discover();

        if ($direction === 'down') {
            $catalog = array_reverse($catalog);
        }

        $completed         = 0;
        $ensuredNamespaces = [];
        $context           = ['client' => $this->client, 'namespace' => $this->namespace, 'direction' => $direction];

        $this->logger->info('Replaying all Temporal migrations', $context + ['count' => count($catalog)]);

        foreach ($catalog as $descriptor) {
            $started          = hrtime(true);
            $migrationContext = $context + ['id' => $descriptor->id, 'path' => $descriptor->path];

            $this->logger->info('Starting Temporal migration', $migrationContext);

            try {
                $rpc = new MigrationRpcContext(
                    $this->service,
                    $this->operator,
                    $this->timeout,
                    $this->logger,
                    ensuredNamespaces: $ensuredNamespaces,
                );
                $scheme    = new Scheme($rpc, $this->namespace, $this->logger);
                $migration = $descriptor->instantiate();

                $migration->{$direction}($scheme);
                $scheme->assertApplied();

                $ensuredNamespaces = $rpc->ensuredNamespaces();
            } catch (Throwable $exception) {
                $this->logger->error('Temporal migration failed; partial effects may remain', $migrationContext + [
                    'completed' => $completed,
                    'exception' => $exception,
                ]);

                throw MigrationException::executionFailed($descriptor->id, $direction, $descriptor->path, $completed, $exception);
            }

            ++$completed;

            $this->logger->info('Completed Temporal migration', $migrationContext);
            $this->logger->debug('Temporal migration duration', $migrationContext + [
                'milliseconds' => (hrtime(true) - $started) / 1e6,
            ]);
        }
    }
}
