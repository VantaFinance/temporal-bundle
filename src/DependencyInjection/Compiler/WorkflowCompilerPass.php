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
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Common\Versioning\VersioningBehavior;
use Temporal\Common\Versioning\WorkerDeploymentVersion;
use Temporal\Exception\ExceptionInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Temporal\Testing;
use Temporal\Testing\ActivityMocker;
use Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface;
use Temporal\Worker\ActivityInvocationCache\InMemoryActivityInvocationCache;
use Temporal\Worker\ActivityInvocationCache\RoadRunnerActivityInvocationCache;
use Temporal\Worker\FeatureFlags;
use Temporal\Worker\ServiceCredentials;
use Temporal\Worker\Transport\Goridge;
use Temporal\Worker\WorkerDeploymentOptions;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Temporal\Worker\WorkflowPanicPolicy;
use Temporal\WorkerFactory;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\dateIntervalDefinition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrineClearEntityManagerFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrinePingFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\getInterceptorsForIntegration;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\globalLogger;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\isGrpcOnlyInterceptor;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\serviceClass;

use const Vanta\Integration\Symfony\Temporal\DependencyInjection\WORKER_FACTORY_ID;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\workerId;

use Vanta\Integration\Symfony\Temporal\Environment;
use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\Runtime\FeatureFlagsConfigurator;
use Vanta\Integration\Symfony\Temporal\Runtime\Runtime;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ActivityDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkerDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkflowDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkflowReplayCommand;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class WorkflowCompilerPass implements CompilerPass
{
    /**
     * @internal
     */
    public const BUNDLE_ONLY_WORKER_KEYS = [
        'taskQueue',
        'logger',
        'exceptionInterceptor',
        'finalizers',
        'interceptors',
        'useSentryIntegration',
        'useDoctrineIntegration',
        'useLoggingDoctrineOpenTransaction',
        'useTrackingSentryDoctrineOpenTransaction',
        'deploymentOptions',
    ];

    /**
     * @internal
     */
    public const WORKER_OPTION_SINCE = [
        'disablePayloadErrorLimit'                    => '2.20',
        'maxEagerActivityReservationsPerWorkflowTask' => '2.20',
    ];

    /**
     * @internal
     */
    public const MINIMAL_SDK_VERSION = '2.16';

    private const WORKFLOW_MOCKER = 'Temporal\Testing\WorkflowMocker';

    private const SEARCH_ATTRIBUTE_MOCKER = 'Temporal\Testing\SearchAttributeMocker';

    private const RR_CHILD_WORKFLOW_CACHE = 'Temporal\Worker\ChildWorkflowInvocationCache\RoadRunnerChildWorkflowInvocationCache';

    private const IN_MEMORY_CHILD_WORKFLOW_CACHE = 'Temporal\Worker\ChildWorkflowInvocationCache\InMemoryChildWorkflowInvocationCache';

    private const RR_SEARCH_ATTRIBUTE_CACHE = 'Temporal\Worker\SearchAttributeInvocationCache\RoadRunnerSearchAttributeInvocationCache';

    private const IN_MEMORY_SEARCH_ATTRIBUTE_CACHE = 'Temporal\Worker\SearchAttributeInvocationCache\InMemorySearchAttributeInvocationCache';


    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config = $container->getParameter('temporal.config');

        $workerFactoryClass = WorkerFactory::class;

        $factoryArguments = [
            '$credentials' => null,
            '$converter'   => new Reference($config['pool']['dataConverter']),
            '$rpc'         => $config['pool']['rpc'] !== null ? new Reference($config['pool']['rpc']) : definition(Goridge::class)
                ->setFactory([Goridge::class, 'create'])
                ->setArguments([
                    definition(RoadRunnerEnvironment::class)
                        ->setFactory([Environment::class, 'create'])
                        ->setArguments([
                            ['RR_RPC' => $config['pool']['roadrunnerRPC']],
                        ]),
                ]),
        ];


        $testingInterceptors = [];

        if ($config['pool']['testing']['enabled']) {
            $activityMocker = $config['pool']['testing']['activityMocker'];

            if (!in_array($activityMocker, ['in_memory', 'rr_kv'], true) && !$container->has($activityMocker)) {
                throw new InvalidArgumentException(sprintf(
                    'Service "%s" of "temporal.pool.testing.activityMocker" not found; use "in_memory", "rr_kv" or the id of a service implementing %s',
                    $activityMocker,
                    ActivityInvocationCacheInterface::class,
                ));
            }

            $activityMockerClass = in_array($activityMocker, ['in_memory', 'rr_kv'], true) ? null : serviceClass($container, $activityMocker);

            if ($activityMockerClass !== null && !is_subclass_of($activityMockerClass, ActivityInvocationCacheInterface::class)) {
                throw new InvalidArgumentException(sprintf(
                    'Service "%s" of "temporal.pool.testing.activityMocker" (%s) must implement %s (temporal/sdk >= 2.18 added saveConsecutiveCompletions() and saveCompletionWhen())',
                    $activityMocker,
                    $activityMockerClass,
                    ActivityInvocationCacheInterface::class,
                ));
            }

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
                        $config['pool']['testing']['activityCacheName'],
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

            if (SdkFeature::hasClass(self::WORKFLOW_MOCKER)) {
                $converter = new Reference($config['pool']['dataConverter']);
                $inMemory  = $activityMocker == 'in_memory';
                $rpc       = $config['pool']['roadrunnerRPC'];
                $cacheName = $config['pool']['testing']['activityCacheName'];

                $childWorkflowCache = $container->register('temporal.testing.child_workflow_cache', $inMemory ? self::IN_MEMORY_CHILD_WORKFLOW_CACHE : self::RR_CHILD_WORKFLOW_CACHE)
                    ->setArguments($inMemory ? [$converter] : [$rpc, $cacheName, $converter])
                ;

                $searchAttributeCache = $container->register('temporal.testing.search_attribute_cache', $inMemory ? self::IN_MEMORY_SEARCH_ATTRIBUTE_CACHE : self::RR_SEARCH_ATTRIBUTE_CACHE)
                    ->setArguments($inMemory ? [] : [$rpc, $cacheName])
                ;

                $container->register('temporal.testing.workflow_mocker', self::WORKFLOW_MOCKER)
                    ->setArguments([$childWorkflowCache])
                    ->setPublic(true)
                ;
                $container->setAlias(self::WORKFLOW_MOCKER, 'temporal.testing.workflow_mocker')->setPublic(true);

                $container->register('temporal.testing.search_attribute_mocker', self::SEARCH_ATTRIBUTE_MOCKER)
                    ->setArguments([$searchAttributeCache])
                    ->setPublic(true)
                ;
                $container->setAlias(self::SEARCH_ATTRIBUTE_MOCKER, 'temporal.testing.search_attribute_mocker')->setPublic(true);

                $container->register('temporal.testing.mock_child_workflow.interceptor', 'Temporal\Testing\MockChildWorkflowInterceptor')
                    ->setArguments([$childWorkflowCache, $converter])
                ;

                $container->register('temporal.testing.mock_side_effect.interceptor', 'Temporal\Testing\MockSideEffectInterceptor')
                    ->setArguments([$childWorkflowCache, $converter])
                ;

                $container->register('temporal.testing.mock_search_attribute.interceptor', 'Temporal\Testing\MockSearchAttributeInterceptor')
                    ->setArguments([$searchAttributeCache])
                ;

                $testingInterceptors = [
                    'temporal.testing.mock_child_workflow.interceptor',
                    'temporal.testing.mock_side_effect.interceptor',
                    'temporal.testing.mock_search_attribute.interceptor',
                ];
            }
        }

        if ($config['pool']['workerApiKey'] != null) {
            $factoryArguments['$credentials'] = definition(ServiceCredentials::class)
                ->setFactory([ServiceCredentials::class, 'create'])
                ->addMethodCall('withApiKey', [$config['pool']['workerApiKey']], true)
            ;
        }

        if ($config['pool']['workerFactory'] != null) {
            $workerFactoryClass = $config['pool']['workerFactory'];

            // A custom factory receives the testing activity cache only when its create() accepts it
            if (array_key_exists('$activityCache', $factoryArguments) && !SdkFeature::hasParameter($workerFactoryClass, 'create', 'activityCache')) {
                throw new InvalidArgumentException(sprintf(
                    'Worker factory %s (temporal.pool.workerFactory) has no $activityCache parameter in create(), required with temporal.pool.testing.enabled; extend %s or add the parameter',
                    $workerFactoryClass,
                    Testing\WorkerFactory::class,
                ));
            }
        }

        $pool = $config['pool'];

        // A custom pipeline provider replaces the bundle one: the bundle interceptors would be lost silently
        if ($pool['pipelineProvider'] !== null) {
            $sources = [];

            if ($pool['globalInterceptors'] != []) {
                $sources[] = 'temporal.pool.globalInterceptors';
            }

            if ($pool['useGlobalSentryIntegration'] || $pool['useGlobalDoctrineIntegration'] != [] || $pool['useGlobalLoggingDoctrineOpenTransaction'] != [] || $pool['useGlobalTrackingSentryDoctrineOpenTransaction'] != []) {
                $sources[] = 'temporal.pool.useGlobal* integrations';
            }

            foreach ($config['workers'] as $name => $worker) {
                if ($worker['interceptors'] != [] || $worker['useSentryIntegration'] || $worker['useDoctrineIntegration'] != [] || $worker['useLoggingDoctrineOpenTransaction'] != [] || $worker['useTrackingSentryDoctrineOpenTransaction'] != []) {
                    $sources[] = sprintf('temporal.workers.%s interceptors / integrations', $name);
                }
            }

            if ($sources != []) {
                throw new InvalidArgumentException(sprintf(
                    'temporal.pool.pipelineProvider "%s" replaces the bundle interceptor pipeline, so these would be ignored: %s (temporal.pool.testing.disableSearchAttributes adds a global interceptor too). Add the interceptors to your provider and remove them from the bundle configuration.',
                    $pool['pipelineProvider'],
                    implode(', ', $sources),
                ));
            }
        }


        $workerFactory = $container->register(WORKER_FACTORY_ID, WorkerFactoryInterface::class)
            ->setFactory([$workerFactoryClass, 'create'])
            ->setArguments($factoryArguments)
            ->setPublic(true)
        ;

        $featureFlags = array_filter($config['pool']['featureFlags'], static fn (?bool $value): bool => $value !== null);

        foreach (array_keys($featureFlags) as $flag) {
            SdkFeature::requireProperty(FeatureFlags::class, $flag, sprintf('temporal.pool.featureFlags.%s', $flag), Configuration::FEATURE_FLAGS[$flag][0] ?? self::MINIMAL_SDK_VERSION);
        }

        // Flags are global: set them when the worker factory is created, before any worker runs
        if ($featureFlags != []) {
            $workerFactory->setConfigurator([
                $container->register('temporal.feature_flags.configurator', FeatureFlagsConfigurator::class)->setArguments([$featureFlags]),
                '__invoke',
            ]);
        }


        // PHP turns numeric worker names into integer keys; #[AssignWorker] names are strings
        $workerNames = array_map(strval(...), array_keys($config['workers']));

        foreach (['temporal.workflow', 'temporal.activity'] as $tag) {
            foreach ($container->findTaggedServiceIds($tag) as $id => $attributes) {
                /** @var list<non-empty-string> $assignedWorkers */
                $assignedWorkers = $attributes[0]['workers'] ?? [];

                foreach ($assignedWorkers as $assignedWorker) {
                    if (in_array($assignedWorker, $workerNames, true)) {
                        continue;
                    }

                    throw new InvalidArgumentException(sprintf(
                        "#[AssignWorker('%s')] on %s refers to an unknown worker; configured workers: %s",
                        $assignedWorker,
                        $container->getDefinition($id)->getClass() ?? $id,
                        implode(', ', $workerNames),
                    ));
                }
            }
        }

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
            // gRPC interceptors belong to the service clients
            ...array_filter($config['pool']['globalInterceptors'], static fn (string $id): bool => !isGrpcOnlyInterceptor($container, $id)),
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

        $globalLoggerReference = globalLogger($container, $config['pool']['globalLogger']);

        foreach ($config['workers'] as $workerName => $worker) {
            $workerName = (string) $workerName;

            $options = definition(WorkerOptions::class)
                ->setFactory([WorkerOptions::class, 'new'])
            ;

            foreach ($worker as $option => $value) {
                if (in_array($option, self::BUNDLE_ONLY_WORKER_KEYS, true)) {
                    continue;
                }

                // Not configured: keep the SDK default
                if ($value === null || $value === '') {
                    continue;
                }

                // Deprecated in the SDK, false is its default
                if ($option == 'useBuildIDForVersioning' && $value === false) {
                    continue;
                }

                $method = sprintf('with%s', ucfirst($option));

                SdkFeature::requireMethod(
                    WorkerOptions::class,
                    $method,
                    sprintf('temporal.workers.%s.%s', $workerName, $option),
                    self::WORKER_OPTION_SINCE[$option] ?? self::MINIMAL_SDK_VERSION,
                );

                if ($option == 'workflowPanicPolicy') {
                    $value = constant(sprintf('%s::%s', WorkflowPanicPolicy::class, $value));
                }

                if (is_string($value) && (str_ends_with($option, 'Timeout') || str_ends_with($option, 'Interval'))) {
                    $value = dateIntervalDefinition($value);
                }

                $options->addMethodCall($method, [$value], true);
            }

            $deployment = $worker['deploymentOptions'];

            if ($deployment['useVersioning'] || $deployment['buildId'] !== null) {
                $deploymentOptions = definition(WorkerDeploymentOptions::class)
                    ->setFactory([WorkerDeploymentOptions::class, 'new'])
                    ->addMethodCall('withUseVersioning', [$deployment['useVersioning']], true)
                    ->addMethodCall('withDefaultVersioningBehavior', [constant(sprintf('%s::%s', VersioningBehavior::class, $deployment['defaultVersioningBehavior']))], true)
                ;

                if ($deployment['deploymentName'] !== null && $deployment['buildId'] !== null) {
                    $deploymentOptions->addMethodCall('withVersion', [
                        definition(WorkerDeploymentVersion::class)
                            ->setFactory([WorkerDeploymentVersion::class, 'new'])
                            ->setArguments([$deployment['deploymentName'], $deployment['buildId']]),
                    ], true);
                }

                $options->addMethodCall('withDeploymentOptions', [$deploymentOptions], true);
            }

            $interceptors = [
                ...$testingInterceptors,
                ...array_filter($worker['interceptors'], static fn (string $id): bool => !isGrpcOnlyInterceptor($container, $id)),
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

            $loggerWorkerReference = $worker['logger'] ? new Reference($worker['logger']) : $globalLoggerReference;

            $newWorker = $container->register(workerId($workerName), WorkerInterface::class)
                ->setFactory([reference(WORKER_FACTORY_ID), 'newWorker'])
                ->setArguments([
                    $worker['taskQueue'],
                    $options,
                    is_array($worker['exceptionInterceptor'])
                        ? definition(ExceptionInterceptor::class, [$worker['exceptionInterceptor']['retryableErrors']])
                        : new Reference($worker['exceptionInterceptor']),
                    $config['pool']['pipelineProvider'] !== null
                        ? new Reference($config['pool']['pipelineProvider'])
                        : definition(SimplePipelineProvider::class)
                            ->setArguments([
                                array_map(reference(...), array_values(array_unique($interceptors))),
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

            $chain = $container->register(sprintf('temporal.%s.worker.finalizer', $workerName), ChainFinalizer::class)
                ->setArguments([
                    array_map(reference(...), array_unique($finalizers)),
                    referenceLogger(),
                ])
            ;

            $newWorker->addMethodCall('registerActivityFinalizer', [
                definition(Closure::class, [[$chain, 'finalize']])
                    ->setFactory([Closure::class, 'fromCallable']),
            ]);

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
                '$workers'      => $configuredWorkers,
                '$featureFlags' => $featureFlags,
                '$plugins'      => [],
            ])
            ->addTag('console.command')
        ;

        $container->register('temporal.workflow_replay.command', WorkflowReplayCommand::class)
            ->setArguments([
                '$roadrunnerRpc' => $config['pool']['roadrunnerRPC'],
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
}
