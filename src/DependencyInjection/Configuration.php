<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use Closure;
use DateMalformedIntervalStringException;
use Doctrine\ORM\EntityManager;
use InvalidArgumentException;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface as BundleConfiguration;
use Symfony\Component\DependencyInjection\Loader\Configurator\EnvConfigurator;
use Temporal\Api\Enums\V1\QueryRejectCondition;
use Temporal\Internal\Support\DateInterval;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkflowPanicPolicy;
use Temporal\WorkerFactory;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Temporal\Doctrine\Finalizer\Finalizer;
use Vanta\Integration\Temporal\Sentry\SentryWorkflowOutboundCallsInterceptor;

/**
 * @phpstan-type PoolWorkerConfiguration array{
 *  dataConverter: non-empty-string,
 *  roadrunnerRPC: non-empty-string,
 *  globalInterceptors: array<non-empty-string>,
 *  globalFinalizers: array<non-empty-string>,
 *  globalLogger: non-empty-string,
 *  workerApiKey: ?non-empty-string,
 *  testing: array{
 *    enabled: bool,
 *    activityMocker: 'in_memory'|'rr_kv'| non-empty-string,
 *    defaultTestService: non-empty-string,
 *    testServices: non-empty-array<array{address: non-empty-string}>
 *  },
 *  workerFactory: ?class-string<WorkerFactoryInterface>,
 *  useGlobalSentryIntegration: bool,
 *  useGlobalDoctrineIntegration: array<non-empty-string>,
 *  useGlobalLoggingDoctrineOpenTransaction: array<non-empty-string>,
 *  useGlobalTrackingSentryDoctrineOpenTransaction: array<non-empty-string>,
 * }
 *
 * @phpstan-type GrpcContext array{
 *  timeout: array{
 *    value: positive-int,
 *    format: DateInterval::FORMAT_*,
 *  },
 *  options: array<non-empty-string, scalar>,
 *  metadata: array<non-empty-string, scalar>,
 *  retryOptions: array{
 *    initialInterval: ?non-empty-string,
 *    maximumInterval: ?non-empty-string,
 *    backoffCoefficient: float,
 *    maximumAttempts: int<0, max>,
 *    nonRetryableExceptions: array<class-string<\Throwable>>,
 *  },
 * }
 *
 * @phpstan-type Client array{
 *  name: non-empty-string,
 *  apiKey: ?non-empty-string,
 *  address: non-empty-string,
 *  namespace: non-empty-string,
 *  identity: ?non-empty-string,
 *  dataConverter: non-empty-string,
 *  queryRejectionCondition?: ?int,
 *  interceptors: list<non-empty-string>,
 *  clientKey: ?non-empty-string,
 *  clientPem: ?non-empty-string,
 *  grpcContext: GrpcContext,
 * }
 *
 * @phpstan-type ScheduleClient array{
 *   name: non-empty-string,
 *   address: non-empty-string,
 *   namespace: non-empty-string,
 *   apiKey: ?non-empty-string,
 *   identity: ?non-empty-string,
 *   dataConverter: non-empty-string,
 *   queryRejectionCondition?: ?int,
 *   clientKey: ?non-empty-string,
 *   clientPem: ?non-empty-string,
 *   grpcContext: GrpcContext,
 * }
 *
 * @phpstan-type Worker array{
 *  name: non-empty-string,
 *  taskQueue: non-empty-string,
 *  address: non-empty-string,
 *  logger: non-empty-string|null,
 *  exceptionInterceptor: non-empty-string,
 *  maxConcurrentActivityExecutionSize: int,
 *  workerActivitiesPerSecond: float|int,
 *  maxConcurrentLocalActivityExecutionSize: int,
 *  workerLocalActivitiesPerSecond: float|int,
 *  taskQueueActivitiesPerSecond: float|int,
 *  maxConcurrentActivityTaskPollers: int,
 *  maxConcurrentWorkflowTaskExecutionSize: int,
 *  maxConcurrentWorkflowTaskPollers: int,
 *  enableSessionWorker: bool,
 *  sessionResourceId: ?non-empty-string,
 *  maxConcurrentSessionExecutionSize: int,
 *  finalizers: array<int, non-empty-string>,
 *  interceptors: list<non-empty-string>,
 *  useDoctrineIntegration: array<non-empty-string>,
 *  useLoggingDoctrineOpenTransaction: array<non-empty-string>,
 *  useTrackingSentryDoctrineOpenTransaction: array<non-empty-string>,
 *  useSentryIntegration: bool,
 * }
 *
 *
 * @phpstan-type RawConfiguration array{
 *  defaultClient: non-empty-string,
 *  defaultScheduleClient: non-empty-string,
 *  clients: array<non-empty-string, Client>,
 *  scheduleClients: array<non-empty-string, ScheduleClient>,
 *  workers: array<non-empty-string, Worker>,
 *  pool: PoolWorkerConfiguration,
 * }
 */
final class Configuration implements BundleConfiguration
{
    /**
     * @param array<non-empty-string> $connections
     * @param array<non-empty-string> $entityManagers
     */
    public function __construct(
        private readonly array $connections,
        private readonly array $entityManagers,
    ) {
    }


    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('temporal');

        $dateIntervalValidator = static function (?string $v): bool {
            if ($v == null) {
                return false;
            }

            try {
                $value = \DateInterval::createFromDateString($v);
            } catch (DateMalformedIntervalStringException) {
                return true;
            }

            if ($value === false) {
                return true;
            }

            return false;
        };


        $sentryValidator = static function (): bool {
            if (!InstalledVersions::willBeAvailable('sentry/sentry-symfony', SentryBundle::class, [])) {
                return true;
            }

            if (!InstalledVersions::willBeAvailable('vanta/temporal-sentry', SentryWorkflowOutboundCallsInterceptor::class)) {
                return true;
            }

            return false;
        };

        $doctrineIntegrationValidator = function (array $values): bool {
            if (!InstalledVersions::willBeAvailable('doctrine/doctrine-bundle', EntityManager::class, [])) {
                throw new InvalidArgumentException('Install dependencies `composer req orm`');
            }

            if (!InstalledVersions::willBeAvailable('vanta/temporal-doctrine', Finalizer::class, [])) {
                throw new InvalidArgumentException('Install dependencies `composer req temporal-doctrine`');
            }


            if (!(count($values) == count(array_unique($values)))) {
                throw new InvalidArgumentException('Should not be repeated entity-manager');
            }

            if ($values == []) {
                throw new InvalidArgumentException('Please set entity-manager name.');
            }

            $notFoundEntityManages = [];

            if ($this->entityManagers == []) {
                return false;
            }

            foreach ($values as $value) {
                if (!in_array($value, $this->entityManagers, true)) {
                    $notFoundEntityManages[] = $value;
                }
            }

            if ($notFoundEntityManages != []) {
                throw new InvalidArgumentException(sprintf("Not found entity managers: %s", implode(', ', $notFoundEntityManages)));
            }

            return false;
        };

        $loggingDoctrineOpenTransactionValidator = function (array $values): bool {
            if (!InstalledVersions::willBeAvailable('doctrine/doctrine-bundle', EntityManager::class, [])) {
                throw new InvalidArgumentException('Install dependencies `composer req orm`');
            }

            if (!InstalledVersions::willBeAvailable('symfony/monolog-bundle', MonologBundle::class, [])) {
                throw new InvalidArgumentException('Install dependencies `composer req log`');
            }

            if (!(count($values) == count(array_unique($values)))) {
                throw new InvalidArgumentException('Should not be repeated connection');
            }

            if ($values == []) {
                throw new InvalidArgumentException('Please set entity-manager name.');
            }

            $notFoundConnections = [];

            if ($this->connections == []) {
                return false;
            }

            foreach ($values as $value) {
                if (!in_array($value, $this->connections, true)) {
                    $notFoundConnections[] = $value;
                }
            }

            if ($notFoundConnections != []) {
                throw new InvalidArgumentException(sprintf("Not found entity managers: %s", implode(', ', $notFoundConnections)));
            }

            return false;
        };

        $trackingSentryDoctrineOpenTransactionValidator = function (array $values) use ($sentryValidator, $loggingDoctrineOpenTransactionValidator): bool {
            if (!$sentryValidator()) {
                throw new InvalidArgumentException('Install dependencies `composer req sentry temporal-doctrine`');
            }

            $loggingDoctrineOpenTransactionValidator($values);

            return false;
        };


        //@formatter:off
        $treeBuilder->getRootNode()
            ->fixXmlConfig('client', 'clients')
            ->fixXmlConfig('worker', 'workers')
            ->fixXmlConfig('scheduleClient', 'scheduleClients')
            ->children()
                ->scalarNode('defaultClient')
                    ->defaultValue('default')
                ->end()
                ->scalarNode('defaultScheduleClient')
                    ->defaultValue('default')
                ->end()
            ->end()
            ->children()
                ->arrayNode('pool')
                    ->children()
                        ->scalarNode('dataConverter')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('roadrunnerRPC')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('globalLogger')
                            ->defaultValue('monolog.logger.temporal')
                        ->end()
                        ->arrayNode('globalInterceptors')
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated interceptor')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Global interceptors connect to all workers/clients')
                        ->end()

                        ->arrayNode('globalFinalizers')
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated finalizer')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Global finalizers connect to all workers')
                        ->end()

                        ->booleanNode('useGlobalSentryIntegration')
                            ->defaultFalse()
                            ->validate()
                                ->ifTrue($sentryValidator)
                                ->thenInvalid('Install dependencies `composer req sentry/sentry-symfony vanta/temporal-sentry`')
                            ->end()
                            ->info('Connects Sentry integration to all workers')
                        ->end()

                        ->arrayNode('useGlobalDoctrineIntegration')
                            ->validate()
                                ->ifTrue($doctrineIntegrationValidator)
                                ->thenInvalid('Should not be repeated entity-manager')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Connects Doctrine integration to all workers. You need to pass a list to entity-manager.')
                        ->end()

                        ->arrayNode('useGlobalLoggingDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($loggingDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to all workers that reports uncompleted transactions in the logs (monolog) after the action completes. You need to pass a list to connection(dbal).')
                        ->end()

                        ->arrayNode('useGlobalTrackingSentryDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($trackingSentryDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to all workers that reports outstanding transactions in sentry after the activity has completed. You need to pass a list to connection(dbal).')
                        ->end()

                        ->arrayNode('testing')
                            ->addDefaultsIfNotSet()
                            ->fixXmlConfig('testService', 'testServices')
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultFalse()
                                ->end()
                                ->scalarNode('activityMocker')
                                    ->defaultValue('rr_kv')
                                    ->info('Allowed value: in_memory or rr_kv or service id if custom activityMocker')
                                ->end()
                                ->scalarNode('defaultTestService')
                                    ->defaultValue('default')
                                ->end()
                                ->arrayNode('testServices')
                                    ->defaultValue(['default' => [
                                        'address' => (new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString(),
                                    ]])
                                    ->useAttributeAsKey('name')
                                    ->normalizeKeys(false)
                                    ->arrayPrototype()
                                        ->children()
                                            ->scalarNode('address')
                                                ->defaultValue((new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString())->cannotBeEmpty()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()

                        ->scalarNode('workerApiKey')
                            ->defaultNull()
                            ->info('Set the authentication token for API calls.')
                        ->end()

                        ->scalarNode('workerFactory')->defaultValue(null)
                            ->validate()
                                ->ifTrue(static function (?string $v): bool {
                                    if ($v == null) {
                                        return false;
                                    }

                                    $interfaces = class_implements($v);

                                    if (!$interfaces) {
                                        return true;
                                    }


                                    if ($interfaces[WorkerFactoryInterface::class] ?? false) {
                                        return false;
                                    }

                                    return true;
                                })
                                ->thenInvalid(sprintf('workerFactory does not implement interface: %s', WorkerFactoryInterface::class))
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()

            ->children()
                ->arrayNode('workers')
                ->useAttributeAsKey('name')
                ->normalizeKeys(false)
                    ->arrayPrototype()
                    ->children()
                        ->scalarNode('maxConcurrentActivityExecutionSize')
                            ->defaultValue(0)
                        ->info('To set the maximum concurrent activity executions this worker can have.')
                        ->end()
                        ->floatNode('workerActivitiesPerSecond')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                      Sets the rate limiting on number of activities that can be
                                      executed per second per worker. This can be used to limit resources used by the worker.

                                      Notice that the number is represented in float, so that you can set it
                                      to less than 1 if needed. For example, set the number to 0.1 means you
                                      want your activity to be executed once for every 10 seconds. This can be
                                      used to protect down stream services from flooding.
                                    STRING
                            )
                        ->end()
                        ->scalarNode('taskQueue')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                        ->scalarNode('logger')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('useSentryIntegration')
                            ->defaultFalse()
                            ->validate()
                                ->ifTrue($sentryValidator)
                                ->thenInvalid('Install dependencies `composer req sentry/sentry-symfony vanta/temporal-sentry`')
                            ->end()
                            ->info('Connect the integration to a specific worker')
                        ->end()

                        ->arrayNode('useDoctrineIntegration')
                            ->validate()
                                ->ifTrue($doctrineIntegrationValidator)
                                ->thenInvalid('Should not be repeated entity-manager')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()
                            ->end()
                            ->info('Connects the current worker to the Doctrine integration. You need to pass a list to entity-manager.')
                        ->end()

                        ->arrayNode('useLoggingDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($loggingDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to the current worker that reports uncompleted transactions in the logs (monolog) after the action completes. You need to pass a list to connection(dbal).')
                        ->end()

                        ->arrayNode('useTrackingSentryDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($trackingSentryDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to the current worker that reports outstanding transactions in sentry after the activity has completed. You need to pass a list to connection(dbal).')
                        ->end()

                        ->scalarNode('taskQueue')
                            ->isRequired()->cannotBeEmpty()
                        ->end()
                        ->scalarNode('exceptionInterceptor')
                            ->defaultValue('temporal.exception_interceptor')->cannotBeEmpty()
                        ->end()
                        ->arrayNode('finalizers')
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated finalizer')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                        ->end()
                        ->arrayNode('interceptors')
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated interceptor')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                        ->end()
                        ->integerNode('maxConcurrentLocalActivityExecutionSize')
                            ->defaultValue(0)
                            ->info('To set the maximum concurrent local activity executions this worker can have.')
                        ->end()
                        ->floatNode('workerLocalActivitiesPerSecond')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                      Sets the rate limiting on number of local activities that can
                                      be executed per second per worker. This can be used to limit resources used by the worker.

                                      Notice that the number is represented in float, so that you can set it
                                      to less than 1 if needed. For example, set the number to 0.1 means you
                                      want your local activity to be executed once for every 10 seconds. This
                                      can be used to protect down stream services from flooding.
                                    STRING
                            )
                        ->end()
                        ->integerNode('taskQueueActivitiesPerSecond')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                      Sets the rate limiting on number of activities that can be executed per second.

                                      This is managed by the server and controls activities per second for your
                                      entire task queue whereas WorkerActivityTasksPerSecond controls activities only per worker.

                                      Notice that the number is represented in float, so that you can set it
                                      to less than 1 if needed. For example, set the number to 0.1 means you
                                      want your activity to be executed once for every 10 seconds. This can be
                                      used to protect down stream services from flooding.
                                    STRING
                            )
                        ->end()
                        ->integerNode('maxConcurrentActivityTaskPollers')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                       Sets the maximum number of goroutines that will concurrently poll the temporal-server to retrieve activity tasks.
                                       Changing this value will affect the rate at which the worker is able to consume tasks from a task queue.
                                    STRING
                            )
                        ->end()
                        ->integerNode('maxConcurrentWorkflowTaskExecutionSize')
                            ->defaultValue(0)
                            ->info('To set the maximum concurrent workflow task executions this worker can have.')
                        ->end()
                        ->integerNode('maxConcurrentWorkflowTaskPollers')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                      Sets the maximum number of goroutines that will concurrently
                                      poll the temporal-server to retrieve workflow tasks. Changing this value
                                      will affect the rate at which the worker is able to consume tasks from a task queue.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('enableSessionWorker')
                            ->defaultValue(false)
                            ->info('Session workers is for activities within a session. Enable this option to allow worker to process sessions.')
                        ->end()
                        ->scalarNode('sessionResourceId')
                            ->defaultValue(null)
                            ->info(
                                <<<STRING
                                       The identifier of the resource consumed by sessions.
                                       It's the user's responsibility to ensure there's only one worker using this resourceID.
                                       For now, if user doesn't specify one, a new uuid will be used as the resourceID.
                                    STRING
                            )
                        ->end()
                        ->integerNode('maxConcurrentSessionExecutionSize')
                            ->defaultValue(1000)
                            ->info('Sets the maximum number of concurrently running sessions the resource support.')
                        ->end()
                        ->scalarNode('stickyScheduleToStartTimeout')
                            ->defaultNull()
                            ->example('5 seconds')
                            ->validate()
                                ->ifTrue($dateIntervalValidator)
                                ->thenInvalid('Failed parse date-interval')
                            ->end()
                            ->info(
                                <<<STRING
                                    Optional: Sticky schedule to start timeout. The resolution is seconds.
                                    Sticky Execution is to run the workflow tasks for one workflow execution on same worker host.
                                    This is a optimization for workflow execution.
                                    When sticky execution is enabled, worker keeps the workflow state in memory.
                                    New workflow task contains the new history events will be dispatched to the same worker.
                                    If this worker crashes, the sticky workflow task will timeout after StickyScheduleToStartTimeout,
                                    and temporal server will clear the stickiness for that workflow execution and automatically reschedule a new workflow task that is available for any worker to pick up and resume the progress.
                                    STRING
                            )
                        ->end()
                        ->scalarNode('workerStopTimeout')
                            ->defaultNull()
                            ->example('5 seconds')
                            ->validate()
                                ->ifTrue($dateIntervalValidator)
                                ->thenInvalid('Failed parse date-interval, value: %s')
                            ->end()
                            ->info('Optional: worker graceful stop timeout.')
                        ->end()
                        ->scalarNode('deadlockDetectionTimeout')
                            ->defaultNull()
                            ->example('5 seconds')
                            ->validate()
                                ->ifTrue($dateIntervalValidator)
                                ->thenInvalid('Failed parse date-interval, value: %s')
                            ->end()
                            ->info('Optional: If set defines maximum amount of time that workflow task will be allowed to run.')
                        ->end()
                        ->scalarNode('maxHeartbeatThrottleInterval')
                            ->defaultNull()
                            ->example('5 seconds')
                            ->validate()
                                ->ifTrue($dateIntervalValidator)
                                ->thenInvalid('Failed parse date-interval, value: %s')
                            ->end()
                            ->info(
                                <<<STRING
                                     Optional: The default amount of time between sending each pending heartbeat to the server.
                                     This is used if the ActivityOptions do not provide a HeartbeatTimeout.
                                     Otherwise, the interval becomes a value a bit smaller than the given HeartbeatTimeout.
                                    STRING
                            )
                        ->end()
                        ->enumNode('workflowPanicPolicy')
                            ->values([
                                WorkflowPanicPolicy::BlockWorkflow,
                                WorkflowPanicPolicy::FailWorkflow,
                            ])
                            ->defaultValue(WorkflowPanicPolicy::BlockWorkflow)
                            ->validate()
                                ->ifNotInArray([
                                    WorkflowPanicPolicy::BlockWorkflow,
                                    WorkflowPanicPolicy::FailWorkflow,
                                ])
                                ->thenInvalid(sprintf('"workflowPanicPolicy" value is not in the enum: %s', WorkflowPanicPolicy::class))
                            ->end()
                            ->info(
                                <<<STRING
                                        Optional: Sets how workflow worker deals with non-deterministic history events
                                        (presumably arising from non-deterministic workflow definitions or non-backward compatible workflow
                                        definition changes) and other panics raised from workflow code.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('enableLoggingInReplay')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                        Optional: Enable logging in replay.
                                        In the workflow code you can use workflow.GetLogger(ctx) to write logs. By default, the logger will skip log
                                        entry during replay mode so you won't see duplicate logs. This option will enable the logging in replay mode.
                                        This is only useful for debugging purpose.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('disableWorkflowWorker')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                        Optional: If set to true, a workflow worker is not started for this
                                        worker and workflows cannot be registered with this worker. Use this if
                                        you only want your worker to execute activities.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('localActivityWorkerOnly')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                       Optional: If set to true worker would only handle workflow tasks and local activities.
                                       Non-local activities will not be executed by this worker.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('disableEagerActivities')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                       Optional: Disable eager activities. If set to true, activities will not
                                       be requested to execute eagerly from the same workflow regardless
                                       of {@see self::maxConcurrentEagerActivityExecutionSize}.

                                       Eager activity execution means the server returns requested eager
                                       activities directly from the workflow task back to this worker which is
                                       faster than non-eager which may be dispatched to a separate worker.
                                    STRING
                            )
                        ->end()
                        ->integerNode('maxConcurrentEagerActivityExecutionSize')
                            ->defaultValue(0)
                            ->info(
                                <<<STRING
                                     Optional: Maximum number of eager activities that can be running.

                                     When non-zero, eager activity execution will not be requested for
                                     activities schedule by the workflow if it would cause the total number of
                                     running eager activities to exceed this value. For example, if this is
                                     set to 1000 and there are already 998 eager activities executing and a
                                     workflow task schedules 3 more, only the first 2 will request eager execution.

                                     The default of 0 means unlimited and therefore only bound by {@see self::maxConcurrentActivityExecutionSize}.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('disableRegistrationAliasing')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                     Optional: Disable allowing workflow and activity functions that are
                                     registered with custom names from being able to be called with their function references.

                                     Users are strongly recommended to set this as true if they register any
                                     workflow or activity functions with custom names. By leaving this as
                                     false, the historical default, ambiguity can occur between function names
                                     and aliased names when not using string names when executing child workflow or activities.
                                    STRING
                            )
                        ->end()
                        ->scalarNode('buildID')
                            ->defaultValue('')
                            ->info(
                                <<<STRING
                                    Assign a BuildID to this worker. This replaces the deprecated binary checksum concept,
                                    and is used to provide a unique identifier for a set of worker code, and is necessary
                                    to opt in to the Worker Versioning feature. See {@see useBuildIDForVersioning}.
                                    STRING
                            )
                        ->end()
                        ->booleanNode('useBuildIDForVersioning')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                     Optional: If set, opts this worker into the Worker Versioning feature.
                                     It will only operate on workflows it claims to be compatible with.
                                     You must set {@see buildID} if this flag is true.
                                    STRING
                            )
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;


        $clients = $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('clients')
                ->defaultValue(['default' => [
                    'namespace'     => 'default',
                    'address'       => (new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString(),
                    'dataConverter' => 'temporal.data_converter',
                    'grpcContext'   => ['timeout' => ['value' => 5, 'format' => DateInterval::FORMAT_SECONDS]],
                    'interceptors'  => [],
                    'apiKey'        => null,
                ]])
                ->useAttributeAsKey('name')
                ->normalizeKeys(false)
        ;

        $this->addClient($clients, $dateIntervalValidator);

        $scheduleClients = $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('scheduleClients')
            ->defaultValue(['default' => [
                'namespace'     => 'default',
                'address'       => (new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString(),
                'dataConverter' => 'temporal.data_converter',
                'grpcContext'   => ['timeout' => ['value' => 5, 'format' => DateInterval::FORMAT_SECONDS]],
                'interceptors'  => [],
                'apiKey'        => null,
            ]])
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
        ;

        $this->addClient($scheduleClients, $dateIntervalValidator);

        return $treeBuilder;
    }



    /**
     * @param Closure(?string): bool $dateIntervalValidator
     */
    private function addClient(ArrayNodeDefinition $node, Closure $dateIntervalValidator): void
    {

        //@formatter:off
        $node->arrayPrototype()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('namespace')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('address')
                    ->defaultValue((new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString())->cannotBeEmpty()
                ->end()
                ->scalarNode('identity')
                ->end()
                ->scalarNode('dataConverter')
                    ->cannotBeEmpty()->defaultValue('temporal.data_converter')
                ->end()
                ->scalarNode('apiKey')
                    ->defaultNull()
                    ->info('Set the authentication token for API calls.')
                ->end()
                ->scalarNode('clientKey')
                    ->example('%kernel.project_dir%/resource/temporal.key')
                ->end()
                ->scalarNode('clientPem')
                    ->example('%kernel.project_dir%/resource/temporal.pem')
                ->end()
                ->enumNode('queryRejectionCondition')
                    ->values([
                        QueryRejectCondition::QUERY_REJECT_CONDITION_UNSPECIFIED,
                        QueryRejectCondition::QUERY_REJECT_CONDITION_NONE,
                        QueryRejectCondition::QUERY_REJECT_CONDITION_NOT_OPEN,
                        QueryRejectCondition::QUERY_REJECT_CONDITION_NOT_COMPLETED_CLEANLY,
                    ])
                    ->validate()
                        ->ifNotInArray([
                            QueryRejectCondition::QUERY_REJECT_CONDITION_UNSPECIFIED,
                            QueryRejectCondition::QUERY_REJECT_CONDITION_NONE,
                            QueryRejectCondition::QUERY_REJECT_CONDITION_NOT_OPEN,
                            QueryRejectCondition::QUERY_REJECT_CONDITION_NOT_COMPLETED_CLEANLY,
                        ])
                        ->thenInvalid(sprintf('"queryRejectionCondition" value is not in the enum: %s', QueryRejectCondition::class))
                    ->end()
                ->end()
                ->arrayNode('interceptors')
                    ->validate()
                        ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                        ->thenInvalid('Should not be repeated interceptor')
                    ->end()
                    ->defaultValue([])
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('grpcContext')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('timeout')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('value')
                                    ->info('Value connection timeout')
                                    ->defaultValue(5)
                                ->end()
                                ->enumNode('format')
                                    ->info('Interval unit')
                                    ->defaultValue(DateInterval::FORMAT_SECONDS)
                                    ->values([
                                        DateInterval::FORMAT_NANOSECONDS,
                                        DateInterval::FORMAT_MICROSECONDS,
                                        DateInterval::FORMAT_MILLISECONDS,
                                        DateInterval::FORMAT_SECONDS,
                                        DateInterval::FORMAT_MINUTES,
                                        DateInterval::FORMAT_HOURS,
                                        DateInterval::FORMAT_DAYS,
                                        DateInterval::FORMAT_WEEKS,
                                        DateInterval::FORMAT_MONTHS,
                                        DateInterval::FORMAT_YEARS,
                                    ])
                                    ->validate()
                                        ->ifNotInArray([
                                            DateInterval::FORMAT_NANOSECONDS,
                                            DateInterval::FORMAT_MICROSECONDS,
                                            DateInterval::FORMAT_MILLISECONDS,
                                            DateInterval::FORMAT_SECONDS,
                                            DateInterval::FORMAT_MINUTES,
                                            DateInterval::FORMAT_HOURS,
                                            DateInterval::FORMAT_DAYS,
                                            DateInterval::FORMAT_WEEKS,
                                            DateInterval::FORMAT_MONTHS,
                                            DateInterval::FORMAT_YEARS,
                                        ])
                                        ->thenInvalid(sprintf('"format" value is not in the enum: %s', DateInterval::class))
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('options')
                            ->normalizeKeys(false)
                            ->defaultValue([])
                            ->prototype('variable')->end()
                        ->end()
                        ->arrayNode('metadata')
                            ->normalizeKeys(false)
                            ->defaultValue([])
                            ->prototype('variable')->end()
                        ->end()
                            ->arrayNode('retryOptions')
                                ->addDefaultsIfNotSet()
                                ->children()
                                    ->scalarNode('initialInterval')
                                        ->defaultValue('5 seconds')
                                        ->example('30 seconds')
                                        ->info('Backoff interval for the first retry.')
                                        ->validate()
                                             ->ifTrue($dateIntervalValidator)
                                             ->thenInvalid('Failed parse date-interval,value: %s')
                                        ->end()
                                    ->end()
                                    ->scalarNode('maximumInterval')
                                        ->defaultNull()
                                        ->validate()
                                            ->ifTrue($dateIntervalValidator)
                                            ->thenInvalid('Failed parse date-interval,value: %s')
                                        ->end()
                                        ->info(
                                            <<<STRING
                                                    Maximum backoff interval between retries.
                                                    Exponential backoff leads to interval increase.
                                                    This value is the cap of the interval.
                                                    Example: 30 seconds
                                                STRING
                                        )
                                    ->end()
                                    ->floatNode('backoff_coefficient')
                                        ->info(
                                            <<<STRING
                                                    Coefficient used to calculate the next retry backoff interval.
                                                    The next retry interval is previous interval multiplied by this coefficient.
                                                    Note: Must be greater than 1.0
                                                STRING
                                        )
                                    ->end()
                                    ->integerNode('maximumAttempts')
                                        ->info(
                                            <<<STRING
                                                    Maximum number of attempts.
                                                    When exceeded the retries stop even if not expired yet.
                                                    If not set or set to 0, it means unlimited
                                                STRING
                                        )
                                        ->defaultValue(3)
                                    ->end()
                                    ->arrayNode('nonRetryableExceptions')
                                        ->scalarPrototype()
                                            ->info(
                                                <<<STRING
                                                        Non-Retriable errors. This is optional.
                                                        Temporal server will stop retry if error type matches this list.
                                                    STRING
                                            )
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ->end();
    }
}
