<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient as GrpcServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface as ServiceClient;
use Temporal\Client\WorkflowClient as GrpcWorkflowClient;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class ClientCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config = $container->getParameter('temporal.config');

        foreach ($config['clients'] as $name => $client) {
            $options = definition(ClientOptions::class)
                ->addMethodCall('withNamespace', [$client['namespace']], true);

            if ($client['identity'] ?? false) {
                $options->addMethodCall('withIdentity', [$client['identity']], true);
            }

            if ($client['queryRejectionCondition'] ?? false) {
                $options->addMethodCall('withQueryRejectionCondition', [$client['queryRejectionCondition']], true);
            }


            $id = sprintf('temporal.%s.client', $name);

            $container->register($id, WorkflowClient::class)
                ->setFactory([GrpcWorkflowClient::class, 'create'])
                ->setArguments([
                    '$serviceClient' => definition(ServiceClient::class, [$client['address']])
                        ->setFactory([GrpcServiceClient::class, 'create']),

                    '$options'   => $options,
                    '$converter' => new Reference($client['dataConverter']),
                ]);

            if ($name == $config['defaultClient']) {
                $container->setAlias(WorkflowClient::class, $id);
            }

            $container->registerAliasForArgument($id, WorkflowClient::class, sprintf('%sWorkflowClient', $name));
        }
    }
}
