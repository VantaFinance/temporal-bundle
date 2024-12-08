<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Closure;
use Spiral\RoadRunner\Environment as RoadRunnerEnvironment;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\dateIntervalDefinition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\Environment;
use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\Runtime\Runtime;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ActivityDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkerDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkflowDebugCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class WorkflowCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config = $container->getParameter('temporal.config');

        $factory = $container->register('temporal.worker_factory', WorkerFactoryInterface::class)
            ->setFactory([$config["workerFactory"], 'create'])
            ->setArguments([
                new Reference($config['pool']['dataConverter']),
                definition(Goridge::class)
                    ->setFactory([Goridge::class, 'create'])
                    ->setArguments([
                        definition(RoadRunnerEnvironment::class)
                            ->setFactory([Environment::class, 'create'])
                            ->setArguments([
                                ['RR_RPC' => $config['pool']['roadrunnerRPC']],
                            ]),
                    ]),
            ])
            ->setPublic(true)
        ;

        $configuredWorkers        = [];
        $activitiesWithoutWorkers = [];
        $workflowsWithoutWorkers  = [];

        foreach ($config['workers'] as $workerName => $worker) {
            $options = definition(WorkerOptions::class)
                ->setFactory([WorkerOptions::class, 'new'])
            ;

            foreach ($worker as $option => $value) {
                $method = sprintf('with%s', ucfirst($option));

                if (!method_exists(WorkerOptions::class, $method)) {
                    continue;
                }

                if (str_ends_with($option, 'Timeout') || str_ends_with($option, 'Interval')) {
                    if (!is_string($value)) {
                        continue;
                    }

                    $value = dateIntervalDefinition($value);
                }

                $options->addMethodCall($method, [$value], true);
            }

            $newWorker = $container->register(sprintf('temporal.%s.worker', $workerName), WorkerInterface::class)
                ->setFactory([$factory, 'newWorker'])
                ->setArguments([
                    $worker['taskQueue'],
                    $options,
                    new Reference($worker['exceptionInterceptor']),
                    definition(SimplePipelineProvider::class)
                        ->setArguments([
                            array_map(reference(...), $worker['interceptors']),
                        ]),
                ])
                ->setPublic(true)
            ;

            foreach ($container->findTaggedServiceIds('temporal.workflow') as $id => $attributes) {
                $class = $container->getDefinition($id)->getClass();

                if ($class == null) {
                    continue;
                }

                $workerNames = $attributes[0]['workers'] ?? null;

                if ($workerNames == null) {
                    $workflowsWithoutWorkers[] = $class;
                }

                if ($workerNames != null && !in_array($workerName, $workerNames)) {
                    continue;
                }

                $newWorker->addMethodCall('registerWorkflowTypes', [$class]);
            }

            foreach ($container->findTaggedServiceIds('temporal.activity') as $id => $attributes) {
                $class = $container->getDefinition($id)->getClass();

                if ($class == null) {
                    continue;
                }

                $workerNames = $attributes[0]['workers'] ?? null;

                if ($workerNames == null) {
                    $activitiesWithoutWorkers[] = $class;
                }


                if ($workerNames != null && !in_array($workerName, $workerNames)) {
                    continue;
                }

                $newWorker->addMethodCall('registerActivity', [
                    $class,
                    new ServiceClosureArgument(new Reference($id)),
                ]);
            }

            $this->registerFinalizers($worker['finalizers'], $workerName, $container);

            $configuredWorkers[$workerName] = $newWorker;
        }


        $container->register('temporal.runtime', Runtime::class)
            ->setArguments([
                $factory,
                $configuredWorkers,
            ])
            ->setPublic(true)
        ;


        $container->register('temporal.worker_debug.command', WorkerDebugCommand::class)
            ->setArguments([
                '$workers' => $configuredWorkers,
            ])
            ->addTag('console.command')
        ;

        $container->register('temporal.workflow_debug.command', WorkflowDebugCommand::class)
            ->setArguments([
                '$workers'                 => $configuredWorkers,
                '$workflowsWithoutWorkers' => $workflowsWithoutWorkers,
            ])
            ->addTag('console.command')
        ;


        $container->register('temporal.activity_debug.command', ActivityDebugCommand::class)
            ->setArguments([
                '$workers'                  => $configuredWorkers,
                '$activitiesWithoutWorkers' => $activitiesWithoutWorkers,
            ])
            ->addTag('console.command')
        ;


        $container->getDefinition('temporal.collector')
            ->setArgument('$workers', array_map(static function (Definition $worker): Definition {
                $worker = clone $worker;

                return $worker->addMethodCall('getOptions', returnsClone: true);
            }, $configuredWorkers))
            ->setArgument('$workflows', $container->findTaggedServiceIds('temporal.workflow'))
            ->setArgument('$activities', $container->findTaggedServiceIds('temporal.activity'))
        ;


        foreach ($container->findTaggedServiceIds('temporal.workflow') as $id => $attributes) {
            $container->removeDefinition($id);
        }
    }

    /**
     * @param array<int, non-empty-string> $finalizers
     * @param non-empty-string $workerName
     */
    private function registerFinalizers(array $finalizers, string $workerName, ContainerBuilder $container): void
    {
        if ($finalizers == []) {
            return;
        }

        $chain = $container->register(sprintf('temporal.%s.worker.finalizer', $workerName), ChainFinalizer::class)
            ->setArguments([
                array_map(reference(...), $finalizers),
                referenceLogger(),
            ])
        ;

        $container->getDefinition(sprintf('temporal.%s.worker', $workerName))
            ->addMethodCall('registerActivityFinalizer', [
                definition(Closure::class, [[$chain, 'finalize']])
                    ->setFactory([Closure::class, 'fromCallable']),
            ])
        ;
    }
}
