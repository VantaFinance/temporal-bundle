<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use Exception;
use ReflectionClass;
use Reflector;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Activity\ActivityInterface as Activity;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;
use Temporal\Workflow\WorkflowInterface as Workflow;
use Vanta\Integration\Symfony\Temporal\Attribute\AsPlugin;
use Vanta\Integration\Symfony\Temporal\DataConverter\DataConverterFactory;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\PluginCompilerPass;
use Vanta\Integration\Symfony\Temporal\Interceptor\DisableSearchAttributesInterceptor;

final class TemporalExtension extends Extension
{
    /**
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('service.php');

        $container->setParameter(Configuration::RR_RPC_DEFAULT_PARAMETER, Configuration::RR_RPC_DEFAULT);

        $entityManagers = [];
        $connections    = [];

        if ($container->hasParameter('doctrine.entity_managers')) {
            /** @var array<non-empty-string, non-empty-string> $rawEntityManagers */
            $rawEntityManagers = $container->getParameter('doctrine.entity_managers');

            $entityManagers = array_keys($rawEntityManagers);
        }

        if ($container->hasParameter('doctrine.connections')) {
            /** @var array<non-empty-string, non-empty-string> $rawConnections */
            $rawConnections = $container->getParameter('doctrine.connections');

            $connections = array_keys($rawConnections);
        }

        $configuration    = new Configuration($connections, $entityManagers);
        $rawConfiguration = $this->processConfiguration($configuration, Configuration::withImplicitDefaultClients($configs));

        if ($rawConfiguration['pool']['testing']['enabled'] && $rawConfiguration['pool']['testing']['disableSearchAttributes']) {
            $disableSearchAttributesInterceptorId = 'temporal.testing.disable_search_attributes.interceptor';

            $container->register($disableSearchAttributesInterceptorId, DisableSearchAttributesInterceptor::class);
            $rawConfiguration['pool']['globalInterceptors'][] = $disableSearchAttributesInterceptorId;
        }


        $container->setParameter('temporal.config', $rawConfiguration);

        if (interface_exists(PluginCompilerPass::PLUGIN_INTERFACE)) {
            $container->registerForAutoconfiguration(PluginCompilerPass::PLUGIN_INTERFACE)->addTag(PluginCompilerPass::TAG);
        }

        $container->registerAttributeForAutoconfiguration(AsPlugin::class, static function (ChildDefinition $definition, AsPlugin $attribute): void {
            $definition->addTag(PluginCompilerPass::TAG, [
                'clients'         => $attribute->clients,
                'scheduleClients' => $attribute->scheduleClients,
                'workers'         => $attribute->workers,
            ]);
        });
        $container->registerAttributeForAutoconfiguration(Workflow::class, workflowConfigurator(...));
        $container->registerAttributeForAutoconfiguration(Activity::class, activityConfigurator(...));

        $useSdkDefaultConverters = $rawConfiguration['pool']['useSdkDefaultConverters'];
        $payloadConverters       = $rawConfiguration['pool']['payloadConverters'];

        if (!$useSdkDefaultConverters && $payloadConverters == []) {
            return;
        }

        $dataConverter = $container->getDefinition('temporal.data_converter');
        $converters    = array_values($dataConverter->getArguments());

        if ($useSdkDefaultConverters) {
            // DataConverter::createDefault() order, its JsonConverter replaced by the bundle one
            $converters = [
                new Definition(NullConverter::class),
                new Definition(BinaryConverter::class),
                new Definition(ProtoJsonConverter::class),
                new Definition(ProtoConverter::class),
                ...$converters,
            ];
        }

        $dataConverter
            ->setFactory([DataConverterFactory::class, 'create'])
            ->setArguments([
                array_map(static fn (string $id): Reference => new Reference($id), $payloadConverters),
                $converters,
            ])
        ;
    }


    /**
     * @param array<string, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        $entityManagers = [];
        $connections    = [];

        if ($container->hasParameter('doctrine.entity_managers')) {
            /** @var array<non-empty-string, non-empty-string> $rawEntityManagers */
            $rawEntityManagers = $container->getParameter('doctrine.entity_managers');

            $entityManagers = array_keys($rawEntityManagers);
        }

        if ($container->hasParameter('doctrine.connections')) {
            /** @var array<non-empty-string, non-empty-string> $rawConnections */
            $rawConnections = $container->getParameter('doctrine.connections');

            $connections = array_keys($rawConnections);
        }


        return new Configuration($connections, $entityManagers);
    }
}


/**
 * @internal
 */
function workflowConfigurator(ChildDefinition $definition, Workflow $attribute, Reflector $reflector): void
{
    if (!$reflector instanceof ReflectionClass) {
        return;
    }

    $assignWorkers = getWorkers($reflector);
    $attributes    = [];

    if ($assignWorkers != []) {
        $attributes['workers'] = $assignWorkers;
    }

    $definition->addTag('temporal.workflow', $attributes);
}


/**
 * @internal
 */
function activityConfigurator(ChildDefinition $definition, Activity $attribute, Reflector $reflector): void
{
    if (!$reflector instanceof ReflectionClass) {
        return;
    }

    $assignWorkers = getWorkers($reflector);
    $attributes    = ['prefix' => $attribute->prefix];

    if ($assignWorkers != []) {
        $attributes['workers'] = $assignWorkers;
    }

    $definition->addTag('temporal.activity', $attributes);
}
