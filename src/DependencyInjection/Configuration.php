<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface as BundleConfiguration;

use Temporal\Internal\Support\DateInterval;
use function Symfony\Component\DependencyInjection\Loader\Configurator\env;

use Temporal\Api\Enums\V1\QueryRejectCondition;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\WorkerFactory;

/**
 * @phpstan-type PoolWorkerConfiguration array{
 *  dataConverter: non-empty-string,
 *  roadrunnerRPC: non-empty-string,
 * }
 *
 * @phpstan-type GrpcContext array{
 *  timeout: array{
 *    value: positive-int,
 *    format: \DateInterval::FORMAT_*,
 *  },
 *  options: array<non-empty-string, scalar>,
 *  metadata: array<non-empty-string, scalar>,
 *  retryOptions: array{
 *    initialInterval: ?non-empty-string,
 *    maximumInterval: ?non-empty-string,
 *    backoffCoefficient: float,
 *    maximumAttempts: int<0, max>,
 *    nonRetryableExceptions: array<class-string<\Throwable>>
 *  },
 * }
 *
 * @phpstan-type Client array{
 *  name: non-empty-string,
 *  address: non-empty-string,
 *  namespace: non-empty-string,
 *  identity: ?non-empty-string,
 *  dataConverter: non-empty-string,
 *  queryRejectionCondition: ?int,
 *  interceptors: list<non-empty-string>,
 *  clientKey: ?non-empty-string,
 *  clientPem: ?non-empty-string,
 *  grpcContext: GrpcContext,
 *
 * @phpstan-type ScheduleClient array{
 *   name: non-empty-string,
 *   address: non-empty-string,
 *   namespace: non-empty-string,
 *   identity: ?non-empty-string,
 *   dataConverter: non-empty-string,
 *   queryRejectionCondition: ?int,
 *  }
 *
 * @phpstan-type Worker array{
 *  name: non-empty-string,
 *  taskQueue: non-empty-string,
 *  address: non-empty-string,
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
 *  finalizers: non-empty-array<int, non-empty-string>,
 *  interceptors: list<non-empty-string>
 * }
 *
 *
 * @phpstan-type RawConfiguration array{
 *  defaultClient: non-empty-string,
 *  defaultScheduleClient: non-empty-string,
 *  workerFactory: class-string<WorkerFactoryInterface>,
 *  clients: array<non-empty-string, Client>,
 *  scheduleClients: array<non-empty-string, ScheduleClient>,
 *  workers: array<non-empty-string, Worker>,
 *  pool: PoolWorkerConfiguration
 * }
 */
final class Configuration implements BundleConfiguration
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('temporal');

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
                ->scalarNode('workerFactory')->defaultValue(WorkerFactory::class)
                    ->validate()
                        ->ifTrue(static function (string $v): bool {
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
            ->children()
                ->arrayNode('pool')
                    ->children()
                        ->scalarNode('dataConverter')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('roadrunnerRPC')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
            ->end()

            ->children()
                ->arrayNode('workers')
                ->useAttributeAsKey('name')
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
                                      entire taskqueue whereas WorkerActivityTasksPerSecond controls activities only per worker.

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
                    ->end()
                ->end()
            ->end()
        ;


        $clients = $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('clients')
                ->defaultValue(['default' => [
                    'namespace'     => 'default',
                    'address'       => env('TEMPORAL_ADDRESS')->__toString(),
                    'dataConverter' => 'temporal.data_converter'],
                ])
                ->useAttributeAsKey('name')
        ;

        $this->addClient($clients);

        $scheduleClients = $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('scheduleClients')
            ->defaultValue(['default' => [
                'namespace'     => 'default',
                'address'       => env('TEMPORAL_ADDRESS')->__toString(),
                'dataConverter' => 'temporal.data_converter'],
            ])
            ->useAttributeAsKey('name')
        ;

        $this->addClient($scheduleClients);

        return $treeBuilder;
    }


    private function addClient(ArrayNodeDefinition $node): void
    {
        $dateIntervalValidator = static function(?string $v): bool {
            if ($v == null){
                return false;
            }

            try {
                $value = \DateInterval::createFromDateString($v);
            }catch (\DateMalformedIntervalStringException){
                return true;
            }

            if ($value === false){
                return true;
            }

            return true;
        };




        //@formatter:off
        $node->arrayPrototype()
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('namespace')
                    ->isRequired()->cannotBeEmpty()
                ->end()
                ->scalarNode('address')
                    ->defaultValue(env('TEMPORAL_ADDRESS')->__toString())->cannotBeEmpty()
                ->end()
                ->scalarNode('identity')
                ->end()
                ->scalarNode('dataConverter')
                    ->cannotBeEmpty()->defaultValue('temporal.data_converter')
                ->end()
                ->scalarNode('clientKey')
                ->end()
                ->scalarNode('clientPem')
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
                    ->children()
                        ->arrayNode('timeout')
                            ->children()
                                ->integerNode('value')
                                    ->info('Value connection timeout')
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
                                ->children()
                                    ->scalarNode('initialInterval')
                                        ->defaultNull()
                                        ->info('Backoff interval for the first retry. Example: 30 seconds')
                                        ->validate()
                                             ->ifTrue($dateIntervalValidator)
                                             ->thenInvalid('{client,scheduleClients}.initialInterval is not valid')
                                        ->end()
                                    ->end()
                                    ->scalarNode('maximumInterval')
                                    ->defaultNull()
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
