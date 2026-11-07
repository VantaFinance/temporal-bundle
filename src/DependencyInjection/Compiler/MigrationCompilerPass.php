<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\GRPC\OperatorClientInterface as OperatorClient;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDiscovery;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationGenerator;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationPaths;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationGenerateCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationMigrateCommand;

/** @phpstan-import-type RawConfiguration from Configuration */
final class MigrationCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config  = $container->getParameter('temporal.config');
        $options = $config['migrations'];

        if (!$options['enabled']) {
            return;
        }

        SdkFeature::requireClass('Temporal\\Client\\GRPC\\OperatorClient', 'temporal.migrations', '2.18');
        SdkFeature::requireClass(OperatorClient::class, 'temporal.migrations', '2.18');

        foreach (['listSearchAttributes', 'addSearchAttributes', 'removeSearchAttributes', 'deleteNamespace'] as $method) {
            SdkFeature::requireMethod(OperatorClient::class, $method, 'temporal.migrations', '2.18');
        }

        $name   = $options['client'] ?? $config['defaultClient'];
        $client = $config['clients'][$name] ?? null;

        if ($client === null) {
            throw new InvalidArgumentException(sprintf('Unknown temporal.migrations.client "%s".', $name));
        }

        if ($client['envConfig']['enabled']) {
            throw new InvalidArgumentException('temporal.migrations does not support envConfig clients; configure a direct client address and credentials.');
        }

        $namespace = $client['namespace'];

        if ($namespace === null || trim($namespace) === '' || str_contains($namespace, "\0")) {
            throw new InvalidArgumentException('The migration client must have a nonempty namespace.');
        }

        $projectDirectory = $container->getParameter('kernel.project_dir');

        if (!is_string($projectDirectory)) {
            throw new InvalidArgumentException('kernel.project_dir must be a string.');
        }

        $paths    = new MigrationPaths($options['paths'], $projectDirectory);
        $loggerId = $config['pool']['globalLogger'];

        if (!$container->has($loggerId)) {
            if ($loggerId !== 'monolog.logger.temporal') {
                throw new InvalidArgumentException(sprintf('Migration logger service "%s" does not exist.', $loggerId));
            }

            $loggerId = 'temporal.migrations.null_logger';
            $container->register($loggerId, NullLogger::class);
        }

        $logger = new Reference($loggerId);

        $container->register(MigrationPaths::class, MigrationPaths::class)
            ->setArguments([$paths->paths, ''])
        ;

        $container->register(MigrationDiscovery::class, MigrationDiscovery::class)
            ->setArguments([new Reference(MigrationPaths::class), $logger])
        ;

        $container->register(MigrationGenerator::class, MigrationGenerator::class)
            ->setArguments([new Reference(MigrationPaths::class), $logger])
        ;

        $container->register(MigrationRunner::class, MigrationRunner::class)
            ->setArguments([
            new Reference(MigrationDiscovery::class),
            new Reference(sprintf('temporal.%s.service_client', $name)),
            new Reference(sprintf('temporal.%s.operator_client', $name)),
            $namespace,
            $options['timeout'],
            $logger,
            $name,
        ])
        ;

        $container->register(MigrationGenerateCommand::class, MigrationGenerateCommand::class)
            ->setArguments([new Reference(MigrationGenerator::class)])
            ->addTag('console.command')
        ;

        $container->register(MigrationMigrateCommand::class, MigrationMigrateCommand::class)
            ->setArguments([
                new Reference(MigrationDiscovery::class),
                new ServiceClosureArgument(new Reference(MigrationRunner::class)),
                $name,
                $namespace,
            ])
            ->addTag('console.command')
        ;
    }
}
