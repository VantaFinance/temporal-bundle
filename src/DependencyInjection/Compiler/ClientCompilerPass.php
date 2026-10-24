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
use Temporal\Client\ActivityCompletionClientInterface as ActivityCompletionClient;
use Temporal\Client\GRPC\ServiceClient as GrpcServiceClient;
use Temporal\Client\WorkflowClient as GrpcWorkflowClient;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;
use Temporal\Interceptor\SimplePipelineProvider;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\clientLogger;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\clientOptions;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\connectionSummary;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcInterceptorPipeline;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\isGrpcInterceptor;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\isGrpcOnlyInterceptor;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\Interceptor\ProfilerWorkflowInterceptor;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ClientDebugCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class ClientCompilerPass implements CompilerPass
{
    private const OPERATOR_CLIENT = 'Temporal\Client\GRPC\OperatorClient';

    private const OPERATOR_CLIENT_INTERFACE = 'Temporal\Client\GRPC\OperatorClientInterface';

    private const CLOUD_CLIENT = 'Temporal\Client\GRPC\CloudClient';

    private const TIME_LOCKING_INTERCEPTOR = 'Temporal\Testing\TimeLockingInterceptor';

    private const CLOUD_CLIENT_INTERFACE = 'Temporal\Client\GRPC\CloudClientInterface';


    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config  = $container->getParameter('temporal.config');
        $clients = [];
        $public  = $config['pool']['testing']['enabled'];

        $globalInterceptors     = array_values(array_filter($config['pool']['globalInterceptors'], static fn (string $id): bool => !isGrpcOnlyInterceptor($container, $id)));
        $globalGrpcInterceptors = array_values(array_filter($config['pool']['globalInterceptors'], static fn (string $id): bool => isGrpcInterceptor($container, $id)));
        $collectorInterceptors  = [];
        $testingInterceptors    = [];

        if ($config['pool']['testing']['enabled'] && $config['pool']['testing']['timeLocking']) {
            SdkFeature::requireClass(self::TIME_LOCKING_INTERCEPTOR, 'temporal.pool.testing.timeLocking', '2.18');

            $container->register('temporal.testing.time_locking.interceptor', self::TIME_LOCKING_INTERCEPTOR)
                ->setArguments([new Reference(sprintf('temporal.testing.%s.testService', $config['pool']['testing']['defaultTestService']))])
            ;

            $testingInterceptors[] = new Reference('temporal.testing.time_locking.interceptor');
        }


        if (!in_array($config['defaultClient'], array_keys($config['clients']))) {
            throw new InvalidArgumentException(sprintf('No default WorkflowClient "%s" configured', $config['defaultClient']));
        }


        foreach ($config['clients'] as $name => $client) {
            $path    = sprintf('temporal.clients.%s', $name);
            $default = $name == $config['defaultClient'];
            $options = clientOptions($client, $path);

            $clientCollectorInterceptors = [];

            if ($container->getParameter('kernel.debug')) {
                $collectorId = sprintf('temporal.workflow_client_collector_%s.interceptor', $name);

                $container->register($collectorId, ProfilerWorkflowInterceptor::class)
                    ->setArgument('$clientName', $name)
                ;

                $collectorInterceptors[]       = reference($collectorId);
                $clientCollectorInterceptors[] = reference($collectorId);
            }

            $serviceClientId = sprintf('temporal.%s.service_client', $name);

            $serviceClient = $container->setDefinition($serviceClientId, grpcClient($client, $path));
            grpcInterceptorPipeline($serviceClient, array_values(array_unique([
                ...array_filter($client['interceptors'], static fn (string $id): bool => isGrpcInterceptor($container, $id)),
                ...$globalGrpcInterceptors,
            ])));

            $id = sprintf('temporal.%s.client', $name);

            $container->register($id, WorkflowClient::class)
                ->setFactory([GrpcWorkflowClient::class, 'create'])
                ->setPublic($public)
                ->setArguments([
                    '$serviceClient'       => new Reference($serviceClientId),
                    '$options'             => $options,
                    '$converter'           => new Reference($client['dataConverter']),
                    '$interceptorProvider' => definition(SimplePipelineProvider::class)
                        ->setArguments([
                            [
                                ...array_map(reference(...), array_values(array_filter($client['interceptors'], static fn (string $id): bool => !isGrpcOnlyInterceptor($container, $id)))),
                                ...$clientCollectorInterceptors,
                                ...array_map(reference(...), $globalInterceptors),
                                ...$testingInterceptors,
                            ],
                        ]),
                ]);

            clientLogger($container, $container->getDefinition($id), GrpcWorkflowClient::class, $client['logger'], $config['pool']['globalLogger'], $path);

            if ($default) {
                $container->setAlias(WorkflowClient::class, $id)->setPublic($public);
            }

            $container->registerAliasForArgument($id, WorkflowClient::class, sprintf('%sWorkflowClient', $name));

            $completionClientId = sprintf('temporal.%s.activity_completion_client', $name);

            $container->register($completionClientId, ActivityCompletionClient::class)
                ->setFactory([new Reference($id), 'newActivityCompletionClient'])
                ->setPublic($public)
            ;

            // An application service of the same interface wins
            if ($default && !$container->has(ActivityCompletionClient::class)) {
                $container->setAlias(ActivityCompletionClient::class, $completionClientId)->setPublic($public);
            }

            $container->registerAliasForArgument($completionClientId, ActivityCompletionClient::class, sprintf('%sActivityCompletionClient', $name));

            // The operator client shares the connection; an environment configuration profile creates a ServiceClient only
            $factory = $serviceClient->getFactory();

            if (SdkFeature::hasClass(self::OPERATOR_CLIENT) && is_array($factory) && $factory[0] === GrpcServiceClient::class) {
                $operatorClientId = sprintf('temporal.%s.operator_client', $name);

                $container->setDefinition($operatorClientId, (clone $serviceClient)->setFactory([self::OPERATOR_CLIENT, $factory[1]]))
                    ->setClass(self::OPERATOR_CLIENT_INTERFACE)
                    ->setPublic($public)
                ;

                if ($default && !$container->has(self::OPERATOR_CLIENT_INTERFACE)) {
                    $container->setAlias(self::OPERATOR_CLIENT_INTERFACE, $operatorClientId)->setPublic($public);
                }

                $container->registerAliasForArgument($operatorClientId, self::OPERATOR_CLIENT_INTERFACE, sprintf('%sOperatorClient', $name));
            }



            $clients[] = [
                'id'   => $id,
                'name' => $name,
                // The debug command must not load the environment configuration profile
                'options'       => $client['envConfig']['enabled'] ? clientOptions(['envConfig' => ['enabled' => false, 'profile' => null, 'configFile' => null], 'namespace' => $client['namespace'] ?? 'from envConfig'] + $client, $path) : $options,
                'dataConverter' => $client['dataConverter'],
                'address'       => $client['envConfig']['enabled'] ? 'envConfig' : $client['address'],
                'connection'    => connectionSummary($client),
                'plugins'       => '',
            ];
        }

        $cloudClient = $config['cloudClient'];

        if ($cloudClient['enabled']) {
            SdkFeature::requireClass(self::CLOUD_CLIENT, 'temporal.cloudClient', '2.19');

            $cloudClientDefinition = $container->register('temporal.cloud_client', self::CLOUD_CLIENT_INTERFACE)
                ->setFactory([self::CLOUD_CLIENT, 'createSSL'])
                ->setArguments([$cloudClient['address']])
                ->addMethodCall('withAuthKey', [str_starts_with((string) $cloudClient['apiKey'], '@') ? new Reference(substr((string) $cloudClient['apiKey'], 1)) : $cloudClient['apiKey']], true)
                ->setPublic($public)
            ;

            if ($cloudClient['apiVersion'] !== null) {
                $cloudClientDefinition->addMethodCall('withApiVersion', [$cloudClient['apiVersion']], true);
            }

            if (!$container->has(self::CLOUD_CLIENT_INTERFACE)) {
                $container->setAlias(self::CLOUD_CLIENT_INTERFACE, 'temporal.cloud_client')->setPublic($public);
            }
        }

        $container->register('temporal.client_debug.command', ClientDebugCommand::class)
            ->setArguments([
                '$clients' => $clients,
            ])
            ->addTag('console.command')
        ;


        $container->getDefinition('temporal.collector')
            ->setArgument('$clients', $clients)
            ->setArgument('$collectorWorkflowClientInterceptors', $collectorInterceptors)
        ;
    }
}
