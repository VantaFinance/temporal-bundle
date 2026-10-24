<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\globalLogger;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;

use const Vanta\Integration\Symfony\Temporal\DependencyInjection\WORKER_FACTORY_ID;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class PluginCompilerPass implements CompilerPass
{
    public const TAG = 'temporal.plugin';

    public const PLUGIN_INTERFACE = 'Temporal\Plugin\PluginInterface';

    private const PLUGIN_REGISTRY = 'Temporal\Plugin\PluginRegistry';

    private const SINCE = '2.18';

    private const TRANSCRIPT_PLUGIN = 'Temporal\Testing\Transcript\TranscriptPlugin';

    private const TRANSCRIPT_WRITER = 'Temporal\Testing\Transcript\TranscriptWriter';


    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config = $container->getParameter('temporal.config');

        // The last bundle pass: keep API keys and certificates out of the compiled container
        $container->getParameterBag()->remove('temporal.config');

        $clientNames         = array_map(strval(...), array_keys($config['clients']));
        $scheduleClientNames = array_map(strval(...), array_keys($config['scheduleClients']));

        $global          = $config['pool']['plugins'];
        $clients         = array_fill_keys($clientNames, []);
        $scheduleClients = array_fill_keys($scheduleClientNames, []);
        $workers         = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $scoped = false;

            foreach ($tags as $tag) {
                /** @var list<string> $tagClients */
                $tagClients = $tag['clients'] ?? [];
                /** @var list<string> $tagScheduleClients */
                $tagScheduleClients = $tag['scheduleClients'] ?? [];
                $tagWorkers         = (bool) ($tag['workers'] ?? false);

                foreach ($tagClients as $name) {
                    $this->assertKnownClient($id, 'clients', $name, $clientNames);

                    $clients[$name][] = $id;
                }

                foreach ($tagScheduleClients as $name) {
                    $this->assertKnownClient($id, 'scheduleClients', $name, $scheduleClientNames);

                    $scheduleClients[$name][] = $id;
                }

                if ($tagWorkers) {
                    $workers[] = $id;
                }

                $scoped = $scoped || $tagClients != [] || $tagScheduleClients != [] || $tagWorkers;
            }

            if (!$scoped) {
                $global[] = $id;
            }
        }

        foreach ($config['clients'] as $name => $client) {
            $clients[(string) $name] = [...$global, ...$clients[(string) $name], ...$client['plugins']];
        }

        foreach ($config['scheduleClients'] as $name => $client) {
            $scheduleClients[(string) $name] = [...$global, ...$scheduleClients[(string) $name], ...$client['plugins']];
        }

        $workers = [...$global, ...$workers];
        $testing = $config['pool']['testing'];

        if ($testing['enabled'] && $testing['transcript'] !== null) {
            SdkFeature::requireClass(self::TRANSCRIPT_PLUGIN, 'temporal.pool.testing.transcript', self::SINCE);

            $container->register('temporal.testing.transcript_writer', self::TRANSCRIPT_WRITER)
                ->setArguments([$testing['transcript'], globalLogger($container, $config['pool']['globalLogger'])])
            ;

            $container->register('temporal.testing.transcript_plugin', self::TRANSCRIPT_PLUGIN)
                ->setArguments([new Reference('temporal.testing.transcript_writer')])
            ;

            $workers[] = 'temporal.testing.transcript_plugin';
        }

        if ($config['pool']['client'] === null && array_merge($workers, ...array_values($clients), ...array_values($scheduleClients)) == []) {
            return;
        }

        SdkFeature::requireClass(self::PLUGIN_REGISTRY, 'temporal.pool.plugins (or a service tagged "temporal.plugin")', self::SINCE);

        foreach ($clients as $name => $plugins) {
            if ($plugins != []) {
                $container->getDefinition(sprintf('temporal.%s.client', $name))->setArgument('$pluginRegistry', $this->registry($plugins));
            }
        }

        foreach ($scheduleClients as $name => $plugins) {
            if ($plugins != []) {
                $container->getDefinition(sprintf('temporal.%s.schedule_client', $name))->setArgument('$pluginRegistry', $this->registry($plugins));
            }
        }

        $client        = $config['pool']['client'];
        $workerPlugins = array_values(array_unique([...($client !== null ? $clients[$client] ?? [] : []), ...$workers]));
        $workerFactory = $container->getDefinition(WORKER_FACTORY_ID);
        $factory       = $workerFactory->getFactory();
        $factoryClass  = is_array($factory) && is_string($factory[0]) ? $factory[0] : null;

        if ($client !== null) {
            if (!in_array($client, $clientNames, true)) {
                throw new InvalidArgumentException(sprintf('temporal.pool.client "%s" is not a configured client; configured clients: %s', $client, implode(', ', $clientNames)));
            }

            if ($factoryClass !== null) {
                SdkFeature::requireParameter($factoryClass, 'create', 'client', 'temporal.pool.client', self::SINCE);
            }

            $workerFactory->setArgument('$client', new Reference(sprintf('temporal.%s.client', $client)));

            // The worker factory registers the client worker plugins first and rejects a duplicate
            $workers = array_values(array_diff($workers, $clients[$client] ?? []));
        }

        if ($workers != []) {
            if ($factoryClass !== null) {
                SdkFeature::requireParameter($factoryClass, 'create', 'pluginRegistry', 'temporal.pool.plugins (worker factory)', self::SINCE);
            }

            $workerFactory->setArgument('$pluginRegistry', $this->registry($workers));
        }

        $this->describe($container, 'temporal.client_debug.command', '$clients', $clients);
        $this->describe($container, 'temporal.schedule_client_debug.command', '$clients', $scheduleClients);
        $this->describe($container, 'temporal.collector', '$clients', $clients);
        $this->describe($container, 'temporal.collector', '$scheduleClients', $scheduleClients);

        if ($container->hasDefinition('temporal.worker_debug.command')) {
            $container->getDefinition('temporal.worker_debug.command')->setArgument('$plugins', $workerPlugins);
        }
    }


    /**
     * @param array<string, list<string>> $plugins
     */
    private function describe(ContainerBuilder $container, string $id, string $argument, array $plugins): void
    {
        if (!$container->hasDefinition($id)) {
            return;
        }

        $definition = $container->getDefinition($id);
        $clients    = $definition->getArguments()[$argument] ?? null;

        if (!is_array($clients)) {
            return;
        }

        $described = [];

        foreach ($clients as $key => $client) {
            if (is_array($client) && is_string($client['name'] ?? null)) {
                $client['plugins'] = implode(', ', array_values(array_unique($plugins[$client['name']] ?? [])));
            }

            $described[$key] = $client;
        }

        $definition->setArgument($argument, $described);
    }


    /**
     * @param list<string> $plugins
     */
    private function registry(array $plugins): Definition
    {
        return new Definition(self::PLUGIN_REGISTRY, [
            array_map(static fn (string $id): Reference => new Reference($id), array_values(array_unique($plugins))),
        ]);
    }


    /**
     * @param list<string> $names
     */
    private function assertKnownClient(string $id, string $kind, string $name, array $names): void
    {
        if (in_array($name, $names, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf('Plugin "%s" refers to an unknown %s "%s"; configured: %s', $id, $kind, $name, implode(', ', $names)));
    }
}
