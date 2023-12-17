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
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\Worker;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\exceptionInspectorId;

use Vanta\Integration\Symfony\Temporal\Environment;
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

        $factory = $container->register('temporal.worker_factory', WorkerFactory::class)
            ->setFactory([WorkerFactory::class, 'create'])
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

                $options->addMethodCall($method, [$value], true);
            }

            $exceptionInterceptorId = exceptionInspectorId($workerName);

            $container->setDefinition(
                $exceptionInterceptorId,
                new ChildDefinition($worker['exceptionInterceptor'])
            );

            $newWorker = $container->register(sprintf('temporal.%s.worker', $workerName), Worker::class)
                ->setFactory([$factory, 'newWorker'])
                ->setArguments([$worker['taskQueue'], $options, new Reference($exceptionInterceptorId)])
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


            foreach ($worker['finalizers'] as $id) {
                $newWorker->addMethodCall('registerActivityFinalizer', [
                    definition(Closure::class, [[new Reference($id), 'finalize']])
                        ->setFactory([Closure::class, 'fromCallable']),
                ]);
            }

            $configuredWorkers[$workerName] = $newWorker;
        }


        foreach ($container->findTaggedServiceIds('temporal.workflow') as $id => $attributes) {
            $container->removeDefinition($id);
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
    }
}
