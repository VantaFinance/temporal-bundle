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
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\ClientOptions;
use Temporal\Client\ScheduleClient as GrpcScheduleClient;
use Temporal\Client\ScheduleClientInterface as ScheduleClient;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient;

use Vanta\Integration\Symfony\Temporal\UI\Cli\ScheduleClientDebugCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class ScheduleClientCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config  = $container->getParameter('temporal.config');
        $clients = [];

        foreach ($config['scheduleClients'] as $name => $client) {
            $options = definition(ClientOptions::class)
                ->addMethodCall('withNamespace', [$client['namespace']], true);

            if ($client['identity'] ?? false) {
                $options->addMethodCall('withIdentity', [$client['identity']], true);
            }

            if (array_key_exists('queryRejectionCondition', $client)) {
                $options->addMethodCall('withQueryRejectionCondition', [$client['queryRejectionCondition']], true);
            }

            $id = sprintf('temporal.%s.schedule_client', $name);

            $container->register($id, ScheduleClient::class)
                ->setFactory([GrpcScheduleClient::class, 'create'])
                ->setArguments([
                    '$serviceClient' => grpcClient($client),
                    '$options'       => $options,
                    '$converter'     => new Reference($client['dataConverter']),
                ]);

            if ($name == $config['defaultScheduleClient']) {
                $container->setAlias(ScheduleClient::class, $id);
            }

            $container->registerAliasForArgument($id, ScheduleClient::class, sprintf('%sScheduleClient', $name));


            $clients[] = [
                'id'            => $id,
                'name'          => $name,
                'options'       => $options,
                'dataConverter' => $client['dataConverter'],
                'address'       => $client['address'],
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
