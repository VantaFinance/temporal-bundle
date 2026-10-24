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
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Testing;
use Temporal\Testing\ActivityMocker;
use Temporal\Worker\ActivityInvocationCache\InMemoryActivityInvocationCache;
use Temporal\Worker\ActivityInvocationCache\RoadRunnerActivityInvocationCache;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\WorkerFactory;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\dateIntervalDefinition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrineClearEntityManagerFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrinePingFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\getInterceptorsForIntegration;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use const Vanta\Integration\Symfony\Temporal\DependencyInjection\WORKER_FACTORY_ID;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\workerId;

use Vanta\Integration\Symfony\Temporal\Environment;
use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
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

        $workerFactoryClass = WorkerFactory::class;

        $factoryArguments = [
            '$credentials' => null,
            '$converter'   => new Reference($config['pool']['dataConverter']),
            '$rpc'         => definition(Goridge::class)
                ->setFactory([Goridge::class, 'create'])
                ->setArguments([
                    definition(RoadRunnerEnvironment::class)
                        ->setFactory([Environment::class, 'create'])
                        ->setArguments([
                            ['RR_RPC' => $config['pool']['roadrunnerRPC']],
                        ]),
                ]),
        ];


        if ($config['pool']['testing']['enabled']) {
            $workerFactoryClass                 = Testing\WorkerFactory::class;
            $factoryArguments['$activityCache'] = new Reference($config['pool']['testing']['activityMocker']);

            if ($config['pool']['testing']['activityMocker'] == 'in_memory') {
                $id = 'temporal.testing.in_memory.activity_cache';

                $container->register($id, InMemoryActivityInvocationCache::class)
                    ->setArguments([
                        new Reference($config['pool']['dataConverter']),
                    ])
                ;

                $factoryArguments['$activityCache'] = new Reference($id);
            }

            if ($config['pool']['testing']['activityMocker'] == 'rr_kv') {
                $id = 'temporal.testing.rr_kv.activity_cache';

                $container->register($id, RoadRunnerActivityInvocationCache::class)
                    ->setArguments([
                        $config['pool']['roadrunnerRPC'],
                        'test',
                        new Reference($config['pool']['dataConverter']),
                    ])
                ;

                $factoryArguments['$activityCache'] = new Reference($id);
            }

            $container->register('temporal.testing.activity_mocker', ActivityMocker::class)
                ->setArguments([
                    $factoryArguments['$activityCache'],
                ])
                ->setPublic(true)
            ;

            $container->setAlias(ActivityMocker::class, 'temporal.testing.activity_mocker')
                ->setPublic(true)
            ;
        }

        if ($config['pool']['workerApiKey'] != null) {
            $factoryArguments['$credentials'] = definition(ServiceCredentials::class)
                ->setFactory([ServiceCredentials::class, 'create'])
                ->addMethodCall('withApiKey', [$config['pool']['workerApiKey']], true)
            ;
        }

        if ($config['pool']['workerFactory'] != null) {
            $workerFactoryClass = $config['pool']['workerFactory'];
        }


        $container->register(WORKER_FACTORY_ID, WorkerFactoryInterface::class)
            ->setFactory([$workerFactoryClass, 'create'])
            ->setArguments($factoryArguments)
            ->setPublic(true)
        ;


        // PHP turns numeric worker names into integer keys; #[AssignWorker] names are strings
        $workerNames = array_map(strval(...), array_keys($config['workers']));

        $this->assertAssignedWorkersExist($container, $workerNames);

        $workflows  = $this->assignments($container, 'temporal.workflow');
        $activities = $this->assignments($container, 'temporal.activity');

        $workflowsWithoutWorkers  = array_keys(array_filter($workflows, static fn (array $assignment): bool => $assignment['workers'] == null));
        $activitiesWithoutWorkers = array_keys(array_filter($activities, static fn (array $assignment): bool => $assignment['workers'] == null));

        $defaultWorker = $config['defaultWorker'];
        $unassigned    = [...$workflowsWithoutWorkers, ...$activitiesWithoutWorkers];

        if ($unassigned != [] && !in_array($defaultWorker, $workerNames, true)) {
            throw new InvalidArgumentException(sprintf(
                'Classes without #[AssignWorker] run on the default worker "%s" (temporal.defaultWorker), which is not configured: %s; configured workers: %s',
                $defaultWorker,
                implode(', ', $unassigned),
                implode(', ', $workerNames),
            ));
        }

        $configuredWorkers = [];


        $globalInterceptors = [
            ...getInterceptorsForIntegration(
                useSentryIntegration: $config['pool']['useGlobalSentryIntegration'],
                useDoctrineIntegration: $config['pool']['useGlobalDoctrineIntegration'],
                useTrackingSentryDoctrineOpenTransaction: $config['pool']['useGlobalTrackingSentryDoctrineOpenTransaction'],
                useLoggingDoctrineOpenTransaction: $config['pool']['useGlobalLoggingDoctrineOpenTransaction']
            ),
            ...$config['pool']['globalInterceptors'],
        ];

        $globalFinalizers = [
            'temporal.framework.finalizer',
        ];

        if ($config['pool']['useGlobalDoctrineIntegration'] != []) {
            $globalFinalizers = [
                ...array_map(doctrinePingFinalizerId(...), $config['pool']['useGlobalDoctrineIntegration']),
                doctrineClearEntityManagerFinalizerId(),
                ...$globalFinalizers,
            ];
        }

        $globalFinalizers = [
            ...$globalFinalizers,
            ...$config['pool']['globalFinalizers'],
        ];

        $globalLoggerReference = new Reference($config['pool']['globalLogger']);
        $isInstalledMonolog    = InstalledVersions::willBeAvailable('symfony/monolog-bundle', MonologBundle::class, []);


        if (!$isInstalledMonolog) {
            $globalLoggerReference = null;
        }

        foreach ($config['workers'] as $workerName => $worker) {
            $workerName = (string) $workerName;

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

            $interceptors = [
                ...$worker['interceptors'],
                ...$globalInterceptors,
                ...getInterceptorsForIntegration(
                    useSentryIntegration: $worker['useSentryIntegration'],
                    useDoctrineIntegration: $worker['useDoctrineIntegration'],
                    useTrackingSentryDoctrineOpenTransaction: $worker['useTrackingSentryDoctrineOpenTransaction'],
                    useLoggingDoctrineOpenTransaction: $worker['useLoggingDoctrineOpenTransaction']
                ),
            ];

            $finalizers = [
                ...$worker['finalizers'],
                ...$globalFinalizers,
            ];

            if ($worker['useDoctrineIntegration'] != []) {
                $finalizers = [
                    ...$finalizers,
                    ...array_map(doctrinePingFinalizerId(...), $worker['useDoctrineIntegration']),
                    doctrineClearEntityManagerFinalizerId(),
                ];
            }

            $loggerWorkerReference = $worker['logger'] ? new Reference($worker['logger']) : null;

            if (!$isInstalledMonolog) {
                $loggerWorkerReference = null;
            }

            $loggerWorkerReference = $loggerWorkerReference ?: $globalLoggerReference;

            $newWorker = $container->register(workerId($workerName), WorkerInterface::class)
                ->setFactory([reference(WORKER_FACTORY_ID), 'newWorker'])
                ->setArguments([
                    $worker['taskQueue'],
                    $options,
                    new Reference($worker['exceptionInterceptor']),
                    definition(SimplePipelineProvider::class)
                        ->setArguments([
                            array_map(reference(...), array_unique($interceptors)),
                        ]),
                    $loggerWorkerReference,
                ])
                ->setPublic(true)
            ;

            // Without #[AssignWorker] a workflow / activity runs on the default worker only
            foreach ($workflows as $class => ['workers' => $assignedWorkers]) {
                if (!in_array($workerName, $assignedWorkers ?? [$defaultWorker], true)) {
                    continue;
                }

                $newWorker->addMethodCall('registerWorkflowTypes', [$class]);
            }

            foreach ($activities as $class => ['id' => $id, 'workers' => $assignedWorkers]) {
                if (!in_array($workerName, $assignedWorkers ?? [$defaultWorker], true)) {
                    continue;
                }

                $newWorker->addMethodCall('registerActivity', [
                    $class,
                    new ServiceClosureArgument(new Reference($id)),
                ]);
            }

            $this->registerFinalizers(array_unique($finalizers), $workerName, $container);

            $configuredWorkers[$workerName] = reference(workerId($workerName));
        }


        $container->register('temporal.runtime', Runtime::class)
            ->setArguments([
                reference(WORKER_FACTORY_ID),
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
                '$defaultWorker'           => $defaultWorker,
            ])
            ->addTag('console.command')
        ;


        $container->register('temporal.activity_debug.command', ActivityDebugCommand::class)
            ->setArguments([
                '$workers'                  => $configuredWorkers,
                '$activitiesWithoutWorkers' => $activitiesWithoutWorkers,
                '$defaultWorker'            => $defaultWorker,
            ])
            ->addTag('console.command')
        ;


        $container->getDefinition('temporal.collector')
            ->setArgument('$workers', array_map(static function (Reference $worker): Definition {
                return definition(WorkerOptions::class)->setFactory([$worker, 'getOptions']);
            }, $configuredWorkers))
            ->setArgument('$workflows', $container->findTaggedServiceIds('temporal.workflow'))
            ->setArgument('$activities', $container->findTaggedServiceIds('temporal.activity'))
        ;


        foreach ($container->findTaggedServiceIds('temporal.workflow') as $id => $attributes) {
            $container->removeDefinition($id);
        }
    }

    /**
     * One entry per class: a class registered under several service ids is still registered once per worker.
     *
     * @return array<class-string, array{id: string, workers: list<non-empty-string>|null}> workers is null without #[AssignWorker]
     */
    private function assignments(ContainerBuilder $container, string $tag): array
    {
        $assignments = [];

        foreach ($container->findTaggedServiceIds($tag) as $id => $attributes) {
            /** @var class-string|null $class */
            $class = $container->getDefinition($id)->getClass();

            if ($class == null || isset($assignments[$class])) {
                continue;
            }

            /** @var list<non-empty-string>|null $workers */
            $workers = $attributes[0]['workers'] ?? null;

            $assignments[$class] = ['id' => $id, 'workers' => $workers];
        }

        return $assignments;
    }

    /**
     * @param list<non-empty-string> $workers
     */
    private function assertAssignedWorkersExist(ContainerBuilder $container, array $workers): void
    {
        foreach (['temporal.workflow', 'temporal.activity'] as $tag) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $attributes) {
                /** @var list<non-empty-string> $assignedWorkers */
                $assignedWorkers = $attributes[0]['workers'] ?? [];

                foreach ($assignedWorkers as $assignedWorker) {
                    if (in_array($assignedWorker, $workers, true)) {
                        continue;
                    }

                    throw new InvalidArgumentException(sprintf(
                        "#[AssignWorker('%s')] on %s refers to an unknown worker; configured workers: %s",
                        $assignedWorker,
                        $container->getDefinition($id)->getClass() ?? $id,
                        implode(', ', $workers),
                    ));
                }
            }
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

        $container->getDefinition(workerId($workerName))
            ->addMethodCall('registerActivityFinalizer', [
                definition(Closure::class, [[$chain, 'finalize']])
                    ->setFactory([Closure::class, 'fromCallable']),
            ])
        ;
    }
}
