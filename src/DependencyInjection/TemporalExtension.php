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

        $configuration = new Configuration();

        $container->setParameter('temporal.config', $this->processConfiguration($configuration, $configs));
        $container->registerAttributeForAutoconfiguration(Workflow::class, workflowConfigurator(...));
        $container->registerAttributeForAutoconfiguration(Activity::class, activityConfigurator(...));
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
