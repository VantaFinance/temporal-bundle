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
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Temporal\Activity\ActivityInterface as Activity;
use Temporal\Workflow\WorkflowInterface as Workflow;

final class TemporalExtension extends Extension
{
    /**
     * @throws Exception
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));

        $loader->load('service.php');

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

        $configuration = new Configuration($connections, $entityManagers);

        $container->setParameter('temporal.config', $this->processConfiguration($configuration, $configs));
        $container->registerAttributeForAutoconfiguration(Workflow::class, workflowConfigurator(...));
        $container->registerAttributeForAutoconfiguration(Activity::class, activityConfigurator(...));
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
