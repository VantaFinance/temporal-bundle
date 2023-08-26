<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Closure;
use Spiral\RoadRunner\Environment as RoadRunnerEnvironment;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\Worker;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;

use Vanta\Integration\Symfony\Temporal\Environment;
use Vanta\Integration\Symfony\Temporal\Runtime\Runtime;

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

        $configuredWorkers = [];

        foreach ($config['workers'] as $name => $worker) {
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


            $newWorker = $container->register(sprintf('temporal.%s.worker', $name), Worker::class)
                ->setFactory([$factory, 'newWorker'])
                ->setArguments([$worker['taskQueue'], $options, new Reference($worker['exceptionInterceptor'])])
                ->setPublic(true)
            ;

            foreach ($container->findTaggedServiceIds('temporal.workflow') as $id => $attributes) {
                $class = $container->getDefinition($id)->getClass();

                if ($class == null) {
                    continue;
                }

                $workerName = $attributes[0]['worker'] ?? null;

                if ($workerName != null && $workerName != $name) {
                    continue;
                }

                $newWorker->addMethodCall('registerWorkflowTypes', [$class]);
            }

            foreach ($container->findTaggedServiceIds('temporal.activity') as $id => $attributes) {
                $class = $container->getDefinition($id)->getClass();

                if ($class == null) {
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

            $configuredWorkers[] = $newWorker;
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
    }
}
