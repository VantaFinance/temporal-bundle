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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Loader\Configurator\EnvConfigurator;
use Temporal\Api\Enums\V1\QueryRejectCondition;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\Interceptor\PipelineProvider;
use Temporal\Internal\Support\DateInterval;
use Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkflowPanicPolicy;
use Temporal\WorkerFactory;
use Throwable;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Temporal\Doctrine\Finalizer\Finalizer;
use Vanta\Integration\Temporal\Sentry\SentryWorkflowOutboundCallsInterceptor;

/**
 * @phpstan-type PoolWorkerConfiguration array{
 *  dataConverter: non-empty-string,
 *  roadrunnerRPC: non-empty-string,
 *  globalInterceptors: list<non-empty-string>,
 *  globalFinalizers: list<non-empty-string>,
 *  globalLogger: non-empty-string,
 *  useSdkDefaultConverters: bool,
 *  payloadConverters: list<non-empty-string>,
 *  workerApiKey: ?non-empty-string,
 *  pipelineProvider: ?non-empty-string,
 *  plugins: list<non-empty-string>,
 *  client: ?non-empty-string,
 *  featureFlags: array<non-empty-string, ?bool>,
 *  rpc: ?non-empty-string,
 *  testing: array{
 *    enabled: bool,
 *    disableSearchAttributes: bool,
 *    activityMocker: 'in_memory'|'rr_kv'|non-empty-string,
 *    defaultTestService: non-empty-string,
 *    activityCacheName: non-empty-string,
 *    timeLocking: bool,
 *    transcript: ?non-empty-string,
 *    testServices: non-empty-array<non-empty-string, array{address: non-empty-string}>
 *  },
 *  workerFactory: ?class-string<WorkerFactoryInterface>,
 *  useGlobalSentryIntegration: bool,
 *  useGlobalDoctrineIntegration: list<non-empty-string>,
 *  useGlobalLoggingDoctrineOpenTransaction: list<non-empty-string>,
 *  useGlobalTrackingSentryDoctrineOpenTransaction: list<non-empty-string>,
 * }
 *
 * @phpstan-type GrpcContext array{
 *  timeout: array{
 *    value: positive-int,
 *    format: DateInterval::FORMAT_*,
 *  },
 *  options: array<non-empty-string, mixed>,
 *  metadata: array<non-empty-string, list<scalar>>,
 *  retryOptions: array{
 *    initialInterval: ?non-empty-string,
 *    maximumInterval: ?non-empty-string,
 *    backoffCoefficient: ?float,
 *    maximumAttempts: ?int<0, max>,
 *    nonRetryableExceptions: list<class-string<\Throwable>>,
 *    congestionInitialInterval: ?non-empty-string,
 *    maximumJitterCoefficient: ?float,
 *  },
 * }
 *
 * @phpstan-type Client array{
 *  namespace: ?non-empty-string,
 *  envConfig: array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string},
 *  address: non-empty-string,
 *  identity?: non-empty-string,
 *  dataConverter: non-empty-string,
 *  apiKey: ?non-empty-string,
 *  tls: array{
 *    enabled: ?bool,
 *    rootCa: ?non-empty-string,
 *    clientKey: ?non-empty-string,
 *    clientPem: ?non-empty-string,
 *    serverName: ?non-empty-string,
 *  },
 *  queryRejectionCondition?: int,
 *  interceptors: list<non-empty-string>,
 *  plugins: list<non-empty-string>,
 *  logger: ?non-empty-string,
 *  payloadLimits?: array{enabled: bool, payloadSizeWarning: ?positive-int, memoSizeWarning: ?positive-int},
 *  grpcContext: GrpcContext,
 * }
 *
 * @phpstan-type ScheduleClient Client
 *
 * @phpstan-type Worker array{
 *  taskQueue: non-empty-string,
 *  logger: ?non-empty-string,
 *  exceptionInterceptor: non-empty-string|array{retryableErrors: list<class-string<\Throwable>>},
 *  finalizers: list<non-empty-string>,
 *  interceptors: list<non-empty-string>,
 *  useSentryIntegration: bool,
 *  useDoctrineIntegration: list<non-empty-string>,
 *  useLoggingDoctrineOpenTransaction: list<non-empty-string>,
 *  useTrackingSentryDoctrineOpenTransaction: list<non-empty-string>,
 *  maxConcurrentActivityExecutionSize: int<0, max>,
 *  workerActivitiesPerSecond: float,
 *  maxConcurrentLocalActivityExecutionSize: int,
 *  workerLocalActivitiesPerSecond: float,
 *  taskQueueActivitiesPerSecond: float,
 *  maxConcurrentActivityTaskPollers: int,
 *  maxConcurrentWorkflowTaskExecutionSize: int,
 *  maxConcurrentWorkflowTaskPollers: int,
 *  enableSessionWorker: bool,
 *  sessionResourceId: ?non-empty-string,
 *  maxConcurrentSessionExecutionSize: int,
 *  stickyScheduleToStartTimeout: ?non-empty-string,
 *  workerStopTimeout: ?non-empty-string,
 *  deadlockDetectionTimeout: ?non-empty-string,
 *  maxHeartbeatThrottleInterval: ?non-empty-string,
 *  workflowPanicPolicy: 'BlockWorkflow'|'FailWorkflow',
 *  enableLoggingInReplay: bool,
 *  disableWorkflowWorker: bool,
 *  localActivityWorkerOnly: bool,
 *  disableEagerActivities: bool,
 *  maxConcurrentEagerActivityExecutionSize: int,
 *  disableRegistrationAliasing: bool,
 *  buildID: string,
 *  useBuildIDForVersioning: bool,
 *  identity: ?non-empty-string,
 *  deploymentOptions: array{
 *    useVersioning: bool,
 *    deploymentName: ?non-empty-string,
 *    buildId: ?non-empty-string,
 *    defaultVersioningBehavior: 'Unspecified'|'Pinned'|'AutoUpgrade',
 *  },
 *  maxConcurrentNexusTaskExecutionSize: ?int<0, max>,
 *  maxConcurrentNexusTaskPollers: ?int<0, max>,
 *  maxEagerActivityReservationsPerWorkflowTask: ?int<0, max>,
 *  disablePayloadErrorLimit: ?bool,
 * }
 *
 *
 * @phpstan-type RawConfiguration array{
 *  defaultClient: non-empty-string,
 *  cloudClient: array{enabled: bool, address: non-empty-string, apiKey: ?non-empty-string, apiVersion: ?non-empty-string},
 *  defaultScheduleClient: non-empty-string,
 *  defaultWorker: non-empty-string,
 *  clients: array<non-empty-string, Client>,
 *  scheduleClients: array<non-empty-string, ScheduleClient>,
 *  workers: array<non-empty-string, Worker>,
 *  pool: PoolWorkerConfiguration,
 * }
 */
final class Configuration implements BundleConfiguration
{
    /**
     * @internal
     */
    public const IMPLICIT_DEFAULT_CLIENT = 'default';

    /**
     * @internal
     */
    public const RR_RPC_DEFAULT_PARAMETER = 'temporal.rr_rpc_default';

    /**
     * @internal
     */
    public const RR_RPC_DEFAULT = 'tcp://127.0.0.1:6001';

    /**
     * @internal
     */
    public const CLOUD_API_ADDRESS = 'saas-api.tmprl.cloud:443';

    /**
     * @internal
     */
    public const FEATURE_FLAGS = [
        'workflowDeferredHandlerStart'            => ['2.11', 'Start signal / update handlers after the workflow method (SDK default: false)'],
        'warnOnWorkflowUnfinishedHandlers'        => ['2.11', 'Warn when a workflow completes with unfinished signal / update handlers (SDK default: true)'],
        'cancelAbandonedChildWorkflows'           => ['2.16', 'Cancel child workflows with the ABANDON parent close policy when the parent is cancelled (SDK default: true)'],
        'throwDestructMemorizedInstanceException' => ['2.16', 'Throw DestructMemorizedInstanceException in the workflow fibers of an evicted workflow (SDK default: true)'],
        'warnOnActivityMethodWithoutAttribute'    => ['2.17.0', 'Warn about public activity methods without #[ActivityMethod] (SDK default: true)'],
        'propagateCancellationToNewScopes'        => ['2.18', 'Propagate the cancellation of a workflow to the scopes created after it (SDK default: false)'],
        'settleAwaitOnFirstSettledCondition'      => ['2.19', 'Workflow::await() settles on the first settled condition; changes the replay behaviour (SDK default: false)'],
        'readOnlyWorkflowCallbacks'               => ['2.20', 'Query handlers and update validators cannot change the workflow state (SDK default: true)'],
    ];


    /**
     * @internal
     *
     * @param array<array-key, mixed> $configs
     *
     * @return array<array-key, mixed>
     */
    public static function withImplicitDefaultClients(array $configs): array
    {
        foreach (['clients' => 'client', 'scheduleClients' => 'scheduleClient'] as $key => $xmlKey) {
            foreach ($configs as $config) {
                if (is_array($config) && (array_key_exists($key, $config) || array_key_exists($xmlKey, $config))) {
                    continue 2;
                }
            }

            $configs[] = [$key => [self::IMPLICIT_DEFAULT_CLIENT => ['namespace' => 'default']]];
        }

        return $configs;
    }


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

            // Env variables are resolved at runtime: "%env(FOO)%" or its placeholder "env_<hash>_FOO_<hash>"
            if (str_contains($v, '%env(') || preg_match('/env_[a-f0-9]{16}_\w+_[a-f0-9]{32}/', $v) === 1) {
                return false;
            }

            try {
                // PHP 8.2 emits a warning and returns false, PHP 8.3+ throws
                $value = @\DateInterval::createFromDateString($v);
            } catch (DateMalformedIntervalStringException) {  /* @phpstan-ignore catch.neverThrown  */
                return true;
            }

            if ($value === false) {   /* @phpstan-ignore identical.alwaysFalse  */
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
                return true;
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
                return true;
            }

            if ($values == []) {
                throw new InvalidArgumentException('Please set connection name.');
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
                throw new InvalidArgumentException(sprintf("Not found connections: %s", implode(', ', $notFoundConnections)));
            }

            return false;
        };

        $trackingSentryDoctrineOpenTransactionValidator = function (array $values) use ($sentryValidator, $loggingDoctrineOpenTransactionValidator): bool {
            if ($sentryValidator()) {
                throw new InvalidArgumentException('Install dependencies `composer req sentry temporal-doctrine`');
            }

            return $loggingDoctrineOpenTransactionValidator($values);
        };


        $featureFlags = (new TreeBuilder('featureFlags'))->getRootNode()
            ->addDefaultsIfNotSet()
            ->info('temporal/sdk feature flags; null keeps the SDK default. Each flag needs the SDK version that added it.')
        ;

        foreach (self::FEATURE_FLAGS as $flag => [$since, $info]) {
            $featureFlags->children()
                ->booleanNode($flag)
                    ->defaultNull()
                    ->info(sprintf('%s. temporal/sdk >= %s', $info, $since))
                ->end()
            ;
        }

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
                ->scalarNode('defaultWorker')
                    ->defaultValue('default')
                    ->cannotBeEmpty()
                    ->info('Worker that runs workflows and activities without #[AssignWorker]')
                ->end()
                ->arrayNode('cloudClient')
                    ->addDefaultsIfNotSet()
                    ->info('temporal/sdk >= 2.19: Temporal Cloud Ops API client (service "temporal.cloud_client", Temporal\\Client\\GRPC\\CloudClientInterface)')
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['enabled'] && ($v['apiKey'] ?? '') === '')
                        ->thenInvalid('"cloudClient.apiKey" is required when "cloudClient.enabled" is true')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('address')
                            ->defaultValue(self::CLOUD_API_ADDRESS)
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('apiKey')
                            ->defaultNull()
                            ->example('%env(TEMPORAL_CLOUD_API_KEY)%')
                            ->info('API key: a value or "@service_id" of a \\Stringable service')
                        ->end()
                        ->scalarNode('apiVersion')
                            ->defaultNull()
                            ->example('2024-10-01-00')
                            ->info('Cloud Ops API version; by default the SDK one')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->children()
                ->arrayNode('pool')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('dataConverter')
                            ->defaultValue('temporal.data_converter')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('roadrunnerRPC')
                            ->defaultValue(sprintf('%%env(default:%s:RR_RPC)%%', self::RR_RPC_DEFAULT_PARAMETER))
                            ->cannotBeEmpty()
                            ->info(sprintf('RoadRunner RPC address. By default the RR_RPC env variable, otherwise the "%s" parameter', self::RR_RPC_DEFAULT_PARAMETER))
                        ->end()
                        ->scalarNode('globalLogger')
                            ->defaultValue('monolog.logger.temporal')
                        ->end()
                        ->booleanNode('useSdkDefaultConverters')
                            ->defaultFalse()
                            ->info(
                                <<<STRING
                                    Use the temporal/sdk default payload converter chain in "temporal.data_converter":
                                    null, binary/plain (Bytes), json/protobuf, binary/protobuf, then the bundle JSON converter.
                                    Enable it only after every worker and client is upgraded: old workers cannot read the new encodings.
                                    STRING
                            )
                        ->end()
                        ->arrayNode('payloadConverters')
                            ->info(sprintf('Service ids of %s added in front of "temporal.data_converter"; a converter replaces the default one with the same encoding', PayloadConverterInterface::class))
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated payload converter')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->cannotBeEmpty()->end()
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
                                ->thenInvalid('Should not be repeated entity-manager: %s')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Connects Doctrine integration to all workers. You need to pass a list to entity-manager.')
                        ->end()

                        ->arrayNode('useGlobalLoggingDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($loggingDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection: %s')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to all workers that reports uncompleted transactions in the logs (monolog) after the action completes. You need to pass a list to connection(dbal).')
                        ->end()

                        ->arrayNode('useGlobalTrackingSentryDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($trackingSentryDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection: %s')
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
                                ->booleanNode('disableSearchAttributes')
                                    ->defaultTrue()
                                ->end()
                                ->scalarNode('activityMocker')
                                    ->defaultValue('rr_kv')
                                    ->cannotBeEmpty()
                                    ->beforeNormalization()
                                        ->ifString()
                                        ->then(static fn (string $v): string => ltrim($v, '@'))
                                    ->end()
                                    ->info(sprintf('Allowed value: in_memory or rr_kv or service id of a custom %s (temporal/sdk >= 2.18 adds saveConsecutiveCompletions() and saveCompletionWhen())', ActivityInvocationCacheInterface::class))
                                ->end()
                                ->scalarNode('defaultTestService')
                                    ->defaultValue('default')
                                ->end()
                                ->booleanNode('timeLocking')
                                    ->defaultFalse()
                                    ->info('temporal/sdk >= 2.18: the workflow clients lock the time skipping of the test server while they wait for a workflow result. Only with the time-skipping test server (TemporalServerType::TestServer)')
                                ->end()
                                ->scalarNode('transcript')
                                    ->defaultNull()
                                    ->cannotBeEmpty()
                                    ->example('%kernel.project_dir%/var/log/temporal-transcript.log')
                                    ->info('temporal/sdk >= 2.18: file the workers write a transcript of workflow and activity calls to')
                                ->end()
                                ->scalarNode('activityCacheName')
                                    ->defaultValue('test')
                                    ->cannotBeEmpty()
                                    ->info('Key-value storage of the "rr_kv" activity mocker: must match a "kv.<name>" section of the RoadRunner config')
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

                        ->scalarNode('pipelineProvider')
                            ->defaultNull()
                            ->info(sprintf('Service id of a %s replacing the bundle interceptor pipeline of the workers. Incompatible with the bundle interceptors and integrations; the testing workflow mocks are not added to it.', PipelineProvider::class))
                        ->end()

                        ->append($featureFlags)
                        ->arrayNode('plugins')
                            ->info('temporal/sdk >= 2.18: service ids of plugins registered in every client, schedule client and the worker factory. Services implementing Temporal\\Plugin\\PluginInterface are registered automatically (see #[AsPlugin] to restrict them).')
                            ->validate()
                                ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                                ->thenInvalid('Should not be repeated plugin')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->cannotBeEmpty()->end()
                        ->end()
                        ->scalarNode('client')
                            ->defaultNull()
                            ->info('temporal/sdk >= 2.18: workflow client passed to the worker factory, so that its worker plugins reach the workers')
                        ->end()
                        ->scalarNode('rpc')
                            ->defaultNull()
                            ->info(sprintf('Service id of a %s used by the worker factory instead of the Goridge connection to "roadrunnerRPC"', RPCConnectionInterface::class))
                        ->end()

                        ->scalarNode('workerFactory')->defaultValue(null)
                            ->info(sprintf('Class of a custom %s; with temporal/sdk >= 2.18 it must implement getPluginRegistry(), with testing enabled its create() needs $activityCache', WorkerFactoryInterface::class))
                            ->validate()
                                ->ifTrue(static fn (?string $v): bool => $v != null && !class_exists($v))
                                ->thenInvalid('workerFactory class %s not found')
                            ->end()
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
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['deploymentOptions']['useVersioning'] && ($v['deploymentOptions']['deploymentName'] === null || $v['deploymentOptions']['buildId'] === null))
                        ->thenInvalid('"deploymentOptions.useVersioning" requires "deploymentOptions.deploymentName" and "deploymentOptions.buildId"')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => ($v['deploymentOptions']['deploymentName'] === null) !== ($v['deploymentOptions']['buildId'] === null))
                        ->thenInvalid('"deploymentOptions.deploymentName" and "deploymentOptions.buildId" must be set together')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['deploymentOptions']['useVersioning'] && $v['useBuildIDForVersioning'])
                        ->thenInvalid('"deploymentOptions.useVersioning" and the deprecated "useBuildIDForVersioning" cannot be enabled together')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => $v['useBuildIDForVersioning'] && $v['enableSessionWorker'])
                        ->thenInvalid('"useBuildIDForVersioning" cannot be enabled together with "enableSessionWorker" (temporal/sdk constraint)')
                    ->end()
                    ->children()
                        ->scalarNode('identity')
                            ->defaultNull()
                            ->cannotBeEmpty()
                            ->info('Optional: identity of the worker in the Temporal UI / history; by default the SDK one')
                        ->end()
                        ->arrayNode('deploymentOptions')
                            ->addDefaultsIfNotSet()
                            ->info('Optional: Worker Deployment Versioning (needs RoadRunner >= 2025.1.3). Replaces the deprecated "buildID" / "useBuildIDForVersioning".')
                            ->children()
                                ->booleanNode('useVersioning')
                                    ->defaultFalse()
                                    ->info('Opts the worker into Worker Deployment Versioning: it polls only the tasks of its deployment version')
                                ->end()
                                ->scalarNode('deploymentName')
                                    ->defaultNull()
                                    ->cannotBeEmpty()
                                    ->example('billing-service')
                                ->end()
                                ->scalarNode('buildId')
                                    ->defaultNull()
                                    ->cannotBeEmpty()
                                    ->example('%env(GIT_COMMIT)%')
                                ->end()
                                ->enumNode('defaultVersioningBehavior')
                                    ->values(['Unspecified', 'Pinned', 'AutoUpgrade'])
                                    ->defaultValue('Unspecified')
                                    ->info('Versioning behavior of the workflows without an explicit one')
                                ->end()
                            ->end()
                        ->end()
                        ->integerNode('maxConcurrentNexusTaskExecutionSize')
                            ->defaultNull()
                            ->min(0)
                            ->info('Optional: maximum concurrent Nexus task executions of this worker')
                        ->end()
                        ->integerNode('maxConcurrentNexusTaskPollers')
                            ->defaultNull()
                            ->min(0)
                            ->info('Optional: maximum number of pollers of Nexus tasks')
                        ->end()
                        ->integerNode('maxEagerActivityReservationsPerWorkflowTask')
                            ->defaultNull()
                            ->min(0)
                            ->info('Optional, temporal/sdk >= 2.20 and RoadRunner 3: maximum eager activity reservations per workflow task')
                        ->end()
                        ->booleanNode('disablePayloadErrorLimit')
                            ->defaultNull()
                            ->info('Optional, temporal/sdk >= 2.20: disables the payload size error limit of the worker')
                        ->end()
                        ->integerNode('maxConcurrentActivityExecutionSize')
                            ->defaultValue(0)
                            ->min(0)
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
                                ->thenInvalid('Should not be repeated entity-manager: %s')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()
                            ->end()
                            ->info('Connects the current worker to the Doctrine integration. You need to pass a list to entity-manager.')
                        ->end()

                        ->arrayNode('useLoggingDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($loggingDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection: %s')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to the current worker that reports uncompleted transactions in the logs (monolog) after the action completes. You need to pass a list to connection(dbal).')
                        ->end()

                        ->arrayNode('useTrackingSentryDoctrineOpenTransaction')
                            ->validate()
                                ->ifTrue($trackingSentryDoctrineOpenTransactionValidator)
                                ->thenInvalid('Should not be repeated connection: %s')
                            ->end()
                            ->defaultValue([])
                            ->scalarPrototype()->end()
                            ->info('Attaches an interceptor to the current worker that reports outstanding transactions in sentry after the activity has completed. You need to pass a list to connection(dbal).')
                        ->end()

                        ->variableNode('exceptionInterceptor')
                            ->defaultValue('temporal.exception_interceptor')
                            ->info('Service id of an exception interceptor, or {retryableErrors: [<Throwable class>, ...]}: workflow task errors of these classes are retried')
                            ->example(['retryableErrors' => ['Temporal\\Exception\\Failure\\ApplicationFailure']])
                            ->validate()
                                ->ifTrue(static function (mixed $v): bool {
                                    if (is_string($v)) {
                                        return $v === '';
                                    }

                                    if (!is_array($v) || array_keys($v) !== ['retryableErrors'] || !is_array($v['retryableErrors']) || !array_is_list($v['retryableErrors'])) {
                                        return true;
                                    }

                                    foreach ($v['retryableErrors'] as $class) {
                                        if (!is_string($class) || !is_a($class, Throwable::class, true)) {
                                            return true;
                                        }
                                    }

                                    return false;
                                })
                                ->thenInvalid('"exceptionInterceptor" must be a service id or {retryableErrors: [<Throwable class>, ...]}: %s')
                            ->end()
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
                        ->floatNode('taskQueueActivitiesPerSecond')
                            ->defaultValue(0)
                            ->min(0)
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
                            ->beforeNormalization()
                                ->ifTrue(static fn (mixed $v): bool => $v instanceof WorkflowPanicPolicy)
                                ->then(static fn (WorkflowPanicPolicy $v): string => $v->name)
                            ->end()
                            ->values(array_map(static fn (WorkflowPanicPolicy $case): string => $case->name, WorkflowPanicPolicy::cases()))
                            ->defaultValue(WorkflowPanicPolicy::BlockWorkflow->name)
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
                            ->setDeprecated('vanta/temporal-bundle', '0.5', 'The "%node%" option is deprecated, use "deploymentOptions" instead.')
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
                            ->setDeprecated('vanta/temporal-bundle', '0.5', 'The "%node%" option is deprecated, use "deploymentOptions" instead.')
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
                ->info(sprintf('Without "clients" in any config file the "%s" client is added (see %s::withImplicitDefaultClients())', self::IMPLICIT_DEFAULT_CLIENT, self::class))
                ->useAttributeAsKey('name')
                ->normalizeKeys(false)
        ;

        $this->addClient($clients, $dateIntervalValidator);

        $scheduleClients = $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('scheduleClients')
            ->info(sprintf('Without "scheduleClients" in any config file the "%s" schedule client is added (see %s::withImplicitDefaultClients())', self::IMPLICIT_DEFAULT_CLIENT, self::class))
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
            ->beforeNormalization()
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && (array_key_exists('clientKey', $v) || array_key_exists('clientPem', $v)))
                ->then(static function (array $v): array {
                    trigger_deprecation('vanta/temporal-bundle', '0.5', 'The "clientKey" and "clientPem" client options are deprecated, use "tls.clientKey" and "tls.clientPem" instead.');

                    $tls = is_array($v['tls'] ?? null) ? $v['tls'] : [];

                    foreach (['clientKey', 'clientPem'] as $key) {
                        if (array_key_exists($key, $v)) {
                            $tls[$key] ??= $v[$key];
                        }

                        unset($v[$key]);
                    }

                    $v['tls'] = $tls;

                    return $v;
                })
            ->end()
            ->beforeNormalization()
                ->ifTrue(static fn (mixed $v): bool => is_array($v) && is_array($v['envConfig'] ?? null) && ($v['envConfig']['enabled'] ?? false) === true)
                ->then(static function (array $v): array {
                    $conflicts = array_values(array_intersect(['address', 'apiKey', 'tls', 'clientKey', 'clientPem'], array_keys($v)));

                    if ($conflicts != []) {
                        throw new InvalidConfigurationException(sprintf('Options "%s" cannot be set together with "envConfig.enabled": the connection comes from the environment configuration profile.', implode('", "', $conflicts)));
                    }

                    return $v;
                })
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['namespace'] ?? null) === null && !$v['envConfig']['enabled'])
                ->thenInvalid('"namespace" is required unless "envConfig.enabled" is true')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => $v['envConfig']['enabled'] && ($v['apiKey'] !== null || array_filter($v['tls'], static fn (mixed $value): bool => $value !== null) != []))
                ->thenInvalid('"apiKey" and "tls" cannot be set together with "envConfig.enabled": the connection comes from the environment configuration profile')
            ->end()
            ->children()
                ->scalarNode('namespace')
                    ->defaultNull()
                    ->cannotBeEmpty()
                    ->info('Required unless "envConfig.enabled" is true; then it overrides the profile namespace')
                ->end()
                ->arrayNode('envConfig')
                    ->addDefaultsIfNotSet()
                    ->info('temporal/sdk >= 2.17 (experimental): the connection (address, namespace, API key, TLS, gRPC metadata) comes from a profile of the TOML file and the TEMPORAL_* env variables')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->scalarNode('profile')
                            ->defaultNull()
                            ->info('Profile name; by default TEMPORAL_PROFILE or "default"')
                        ->end()
                        ->scalarNode('configFile')
                            ->defaultNull()
                            ->example('%kernel.project_dir%/temporal.toml')
                            ->info('TOML file path or content; by default TEMPORAL_CONFIG_FILE or the platform default path. Needs "composer require internal/toml"')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('address')
                    ->defaultValue((new EnvConfigurator('TEMPORAL_ADDRESS'))->__toString())->cannotBeEmpty()
                ->end()
                ->scalarNode('identity')
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('dataConverter')
                    ->cannotBeEmpty()->defaultValue('temporal.data_converter')
                ->end()
                ->scalarNode('apiKey')
                    ->defaultNull()
                    ->example('%env(TEMPORAL_API_KEY)%')
                    ->info('Authentication token for API calls: a value or "@service_id" of a \\Stringable service. A non-empty key turns TLS on unless "tls.enabled" is false.')
                ->end()
                ->arrayNode('tls')
                    ->addDefaultsIfNotSet()
                    ->info('TLS of the gRPC connection. Certificate values are a file path or PEM content.')
                    ->children()
                        ->enumNode('enabled')
                            ->values([null, true, false])
                            ->defaultNull()
                            ->info('null: TLS when a certificate, "serverName" or a non-empty "apiKey" is set; true: always TLS (system root CA by default); false: insecure connection')
                        ->end()
                        ->scalarNode('rootCa')
                            ->defaultNull()
                            ->example('%kernel.project_dir%/resource/ca.pem')
                            ->info('Root CA certificate; the system roots are used when empty')
                        ->end()
                        ->scalarNode('clientKey')
                            ->defaultNull()
                            ->example('%kernel.project_dir%/resource/temporal.key')
                            ->info('Client private key (mTLS), set together with "clientPem"')
                        ->end()
                        ->scalarNode('clientPem')
                            ->defaultNull()
                            ->example('%kernel.project_dir%/resource/temporal.pem')
                            ->info('Client certificate chain (mTLS), set together with "clientKey"')
                        ->end()
                        ->scalarNode('serverName')
                            ->defaultNull()
                            ->info('Overrides the server name used for the TLS handshake')
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => (($v['clientKey'] ?? '') === '') !== (($v['clientPem'] ?? '') === ''))
                        ->thenInvalid('"tls.clientKey" and "tls.clientPem" must be set together: %s')
                    ->end()
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
                ->scalarNode('logger')
                    ->defaultNull()
                    ->cannotBeEmpty()
                    ->info('temporal/sdk >= 2.20: logger service of the client; by default "pool.globalLogger"')
                ->end()
                ->arrayNode('payloadLimits')
                    ->info('temporal/sdk >= 2.20: payload and memo size warnings of the client. Not configured: SDK defaults (512 KiB / 2 KiB)')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('false disables the size warnings')
                        ->end()
                        ->integerNode('payloadSizeWarning')
                            ->defaultNull()
                            ->min(1)
                            ->info('Payload size, in bytes, that logs a warning')
                        ->end()
                        ->integerNode('memoSizeWarning')
                            ->defaultNull()
                            ->min(1)
                            ->info('Memo size, in bytes, that logs a warning')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('plugins')
                    ->info('temporal/sdk >= 2.18: service ids of plugins of this client only')
                    ->validate()
                        ->ifTrue(static fn (array $values): bool => !(count($values) == count(array_unique($values))))
                        ->thenInvalid('Should not be repeated plugin')
                    ->end()
                    ->defaultValue([])
                    ->scalarPrototype()->cannotBeEmpty()->end()
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
                                    ->min(1)
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
                            ->info('gRPC headers merged into the SDK headers (client-name, client-version). A scalar value is a single-value header.')
                            ->example(['x-team' => 'billing', 'x-tags' => ['a', 'b']])
                            ->normalizeKeys(false)
                            ->defaultValue([])
                            ->arrayPrototype()
                                ->beforeNormalization()
                                    ->ifTrue(static fn (mixed $v): bool => is_scalar($v))
                                    ->then(static fn (mixed $v): array => [$v])
                                ->end()
                                ->scalarPrototype()->end()
                            ->end()
                        ->end()
                            ->arrayNode('retryOptions')
                                ->info('RPC retry policy. Unset values keep the temporal/sdk defaults (500 ms initial interval, unlimited attempts).')
                                ->addDefaultsIfNotSet()
                                ->beforeNormalization()
                                    ->ifTrue(static fn (mixed $v): bool => is_array($v) && array_key_exists('backoff_coefficient', $v))
                                    ->then(static function (array $v): array {
                                        trigger_deprecation('vanta/temporal-bundle', '0.5', 'The "grpcContext.retryOptions.backoff_coefficient" option is deprecated, use "backoffCoefficient" instead.');

                                        $v['backoffCoefficient'] ??= $v['backoff_coefficient'];

                                        unset($v['backoff_coefficient']);

                                        return $v;
                                    })
                                ->end()
                                ->children()
                                    ->scalarNode('initialInterval')
                                        ->defaultNull()
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
                                    ->floatNode('backoffCoefficient')
                                        ->defaultNull()
                                        ->min(1)
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
                                        ->defaultNull()
                                        ->min(0)
                                    ->end()
                                    ->scalarNode('congestionInitialInterval')
                                        ->defaultNull()
                                        ->example('1 second')
                                        ->info('Backoff interval for the first retry on congestion related failures (RESOURCE_EXHAUSTED). The SDK default is 1 second.')
                                        ->validate()
                                            ->ifTrue($dateIntervalValidator)
                                            ->thenInvalid('Failed parse date-interval,value: %s')
                                        ->end()
                                    ->end()
                                    ->floatNode('maximumJitterCoefficient')
                                        ->defaultNull()
                                        ->min(0)
                                        ->info('Maximum jitter applied to the retry interval, in range [0, 1). 0.1 means +/- 10%. The SDK default is 0.1.')
                                        ->validate()
                                            ->ifTrue(static fn (?float $v): bool => $v !== null && $v >= 1.0)
                                            ->thenInvalid('"maximumJitterCoefficient" must be lower than 1, value: %s')
                                        ->end()
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
