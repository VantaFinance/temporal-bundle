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
use Temporal\Client\ClientOptions;
use Temporal\Client\WorkflowClient as GrpcWorkflowClient;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;
use Temporal\Interceptor\SimplePipelineProvider;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;

use Vanta\Integration\Symfony\Temporal\Interceptor\ProfilerWorkflowInterceptor;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ClientDebugCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class ClientCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config  = $container->getParameter('temporal.config');
        $clients = [];

        $globalInterceptors    = array_map(reference(...), $config['pool']['globalInterceptors']);
        $collectorInterceptors = [];


        if (!in_array($config['defaultClient'], array_keys($config['clients']))) {
            throw new InvalidArgumentException(sprintf('No default WorkflowClient "%s" configured', $config['defaultClient']));
        }


        foreach ($config['clients'] as $name => $client) {
            $options = definition(ClientOptions::class)
                ->addMethodCall('withNamespace', [$client['namespace']], true);

            if ($client['identity'] ?? false) {
                $options->addMethodCall('withIdentity', [$client['identity']], true);
            }

            if (array_key_exists('queryRejectionCondition', $client)) {
                $options->addMethodCall('withQueryRejectionCondition', [$client['queryRejectionCondition']], true);
            }


            if ($container->getParameter('kernel.debug')) {
                $collectorId = sprintf('temporal.workflow_client_collector_%s.interceptor', $name);

                $container->register($collectorId, ProfilerWorkflowInterceptor::class)
                    ->setArgument('$clientName', $name)
                ;

                $collectorInterceptors[] = reference($collectorId);
            }

            $id = sprintf('temporal.%s.client', $name);

            $container->register($id, WorkflowClient::class)
                ->setFactory([GrpcWorkflowClient::class, 'create'])
                ->setPublic($config['pool']['testing']['enabled'])
                ->setArguments([
                    '$serviceClient'       => grpcClient($client),
                    '$options'             => $options,
                    '$converter'           => new Reference($client['dataConverter']),
                    '$interceptorProvider' => definition(SimplePipelineProvider::class)
                        ->setArguments([
                            [
                                ...array_map(reference(...), $client['interceptors']),
                                ...$collectorInterceptors,
                                ...$globalInterceptors,
                            ],
                        ]),
                ]);

            if ($name == $config['defaultClient']) {
                $container->setAlias(WorkflowClient::class, $id);
            }

            $container->registerAliasForArgument($id, WorkflowClient::class, sprintf('%sWorkflowClient', $name));


            $clients[] = [
                'id'            => $id,
                'name'          => $name,
                'options'       => $options,
                'dataConverter' => $client['dataConverter'],
                'address'       => $client['address'],
            ];
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
