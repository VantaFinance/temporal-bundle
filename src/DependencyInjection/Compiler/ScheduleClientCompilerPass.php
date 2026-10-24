<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\ScheduleClient as GrpcScheduleClient;
use Temporal\Client\ScheduleClientInterface as ScheduleClient;
use Temporal\Interceptor\GrpcClientInterceptor;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\clientLogger;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\clientOptions;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\connectionSummary;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\isGrpcInterceptor;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;

use Vanta\Integration\Symfony\Temporal\UI\Cli\ScheduleClientDebugCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class ScheduleClientCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config                 = $container->getParameter('temporal.config');
        $clients                = [];
        $globalGrpcInterceptors = array_values(array_filter($config['pool']['globalInterceptors'], static fn (string $id): bool => isGrpcInterceptor($container, $id)));

        if (!in_array($config['defaultScheduleClient'], array_keys($config['scheduleClients']))) {
            throw new InvalidArgumentException(sprintf('No default ScheduleClient "%s" configured', $config['defaultScheduleClient']));
        }

        foreach ($config['scheduleClients'] as $name => $client) {
            $path    = sprintf('temporal.scheduleClients.%s', $name);
            $options = clientOptions($client, $path);

            // ScheduleClient has no interceptor pipeline: only gRPC interceptors of its service client apply
            foreach ($client['interceptors'] as $interceptor) {
                if (!isGrpcInterceptor($container, $interceptor)) {
                    throw new InvalidArgumentException(sprintf(
                        'Interceptor "%s" of schedule client "%s" (temporal.scheduleClients.%s.interceptors) must implement %s: schedule clients support only gRPC interceptors. Set "class" in the service definition if it is created by a factory',
                        $interceptor,
                        $name,
                        $name,
                        GrpcClientInterceptor::class,
                    ));
                }
            }

            $serviceClientId = sprintf('temporal.%s.schedule_service_client', $name);

            $container->setDefinition($serviceClientId, grpcClient($client, $path))
                ->setArgument('$interceptors', array_map(reference(...), array_values(array_unique([...$client['interceptors'], ...$globalGrpcInterceptors]))))
            ;

            $id = sprintf('temporal.%s.schedule_client', $name);

            $container->register($id, ScheduleClient::class)
                ->setFactory([GrpcScheduleClient::class, 'create'])
                ->setPublic($config['pool']['testing']['enabled'])
                ->setArguments([
                    '$serviceClient' => new Reference($serviceClientId),
                    '$options'       => $options,
                    '$converter'     => new Reference($client['dataConverter']),
                ]);

            clientLogger($container, $container->getDefinition($id), GrpcScheduleClient::class, $client['logger'], $config['pool']['globalLogger'], $path);

            if ($name == $config['defaultScheduleClient']) {
                $container->setAlias(ScheduleClient::class, $id)
                    ->setPublic($config['pool']['testing']['enabled'])
                ;
            }

            $container->registerAliasForArgument($id, ScheduleClient::class, sprintf('%sScheduleClient', $name));


            $clients[] = [
                'id'   => $id,
                'name' => $name,
                // An environment configuration profile is loaded at runtime only, not by the debug command
                'options'       => $client['envConfig']['enabled'] ? clientOptions(['envConfig' => ['enabled' => false, 'profile' => null, 'configFile' => null], 'namespace' => $client['namespace'] ?? 'from envConfig'] + $client, $path) : $options,
                'dataConverter' => $client['dataConverter'],
                'address'       => $client['envConfig']['enabled'] ? 'envConfig' : $client['address'],
                'connection'    => connectionSummary($client),
                'plugins'       => '',
            ];
        }

        $container->register('temporal.schedule_client_debug.command', ScheduleClientDebugCommand::class)
            ->setArguments([
                '$clients' => $clients,
            ])
            ->addTag('console.command')
        ;


        $container->getDefinition('temporal.collector')
            ->setArgument('$scheduleClients', $clients)
        ;
    }
}
