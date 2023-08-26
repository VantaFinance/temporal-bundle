<?php

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
use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
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
        /** @var RawConfiguration $config */
        $config = $this->processConfiguration($configuration, $configs);

        $workflowConfigurator = static function (ChildDefinition $definition, Workflow $attribute, Reflector $reflector): void {
            if (!$reflector instanceof ReflectionClass) {
                return;
            }

            $classAttribute = $reflector->getAttributes(AssignWorker::class)[0] ?? null;
            $assignWorker   = $classAttribute?->newInstance()?->name;

            $attributes = [];

            if ($assignWorker != null) {
                $attributes['worker'] = $assignWorker;
            }

            $definition->addTag('temporal.workflow', $attributes);
        };

        $activityConfigurator = static function (ChildDefinition $definition, Activity $attribute, Reflector $reflector): void {
            $definition->addTag('temporal.activity', ['prefix' => $attribute->prefix]);
        };


        $container->registerAttributeForAutoconfiguration(Workflow::class, $workflowConfigurator);
        $container->registerAttributeForAutoconfiguration(Activity::class, $activityConfigurator);

        $container->setParameter('temporal.config', $config);
    }
}
