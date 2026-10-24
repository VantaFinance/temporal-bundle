<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Common\Versioning\VersioningBehavior;
use Temporal\Common\Versioning\WorkerDeploymentVersion;
use Temporal\Testing\WorkerFactory;
use Temporal\Worker\WorkerDeploymentOptions;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkflowPanicPolicy;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\Runtime\Runtime;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityAHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityBHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityCHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Bundle\TestActivityBundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Bundle\TestWorkflowBundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\AssignWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\AssignWorkflowHandlerV2;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\NullWorkflowHandler;

/**
 * @phpstan-type WorkerOptions array{
 *   withMaxConcurrentActivityExecutionSize: int,
 *   withWorkerActivitiesPerSecond: int,
 *   withMaxConcurrentLocalActivityExecutionSize: int,
 *   withWorkerLocalActivitiesPerSecond: int,
 *   withTaskQueueActivitiesPerSecond: int,
 *   withMaxConcurrentActivityTaskPollers: int,
 *   withMaxConcurrentWorkflowTaskExecutionSize: int,
 *   withMaxConcurrentWorkflowTaskPollers: int,
 *   withEnableSessionWorker: bool,
 *   withSessionResourceId: ?non-empty-string,
 *   withMaxConcurrentSessionExecutionSize: int,
 *   withStickyScheduleToStartTimeout: ?non-empty-string,
 *   withWorkerStopTimeout: ?non-empty-string,
 *   withDeadlockDetectionTimeout: ?non-empty-string,
 *   withMaxHeartbeatThrottleInterval: ?non-empty-string,
 *   withWorkflowPanicPolicy: WorkflowPanicPolicy,
 *   withEnableLoggingInReplay: bool,
 * }
 */

#[RunTestsInSeparateProcesses]
#[CoversClass(Runtime::class)]
#[CoversClass(Configuration::class)]
#[CoversClass(WorkflowCompilerPass::class)]
final class WorkerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, string> $options
     */
    protected static function createKernel(array $options = []): Kernel
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(TemporalBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }


    public function testRegisterWorker(): void
    {
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
        }]);


        $container = $kernel->getContainer();

        assertTrue($container->has('temporal.runtime'));

        /** @var Runtime|null $runtime */
        $runtime = $container->get('temporal.runtime');

        assertNotNull($runtime);
        assertInstanceOf(Runtime::class, $runtime);
        assertCount(3, $runtime);

        $factory = $container->get('temporal.worker_factory');
        assertInstanceOf(\Temporal\WorkerFactory::class, $factory);
    }

    public function testRegisterWorkerWithoutPool(): void
    {
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_without_pool.yaml');
        }]);

        $worker = $kernel->getContainer()->get('temporal.default.worker');

        assertInstanceOf(WorkerInterface::class, $worker);
        assertSame(WorkflowPanicPolicy::FailWorkflow, $worker->getOptions()->workflowPanicPolicy);
    }


    /**
     * @return iterable<string, array{bool, non-empty-string, array<non-empty-string, ?non-empty-string>}>
     */
    public static function workerLoggerDataProvider(): iterable
    {
        $custom = ['temporal.default.worker' => 'app.worker_logger', 'temporal.bar.worker' => 'app.global_logger'];

        yield 'custom loggers without monolog' => [false, 'temporal_with_loggers.yaml', $custom];
        yield 'custom loggers with monolog' => [true, 'temporal_with_loggers.yaml', $custom];
        yield 'default logger without monolog' => [false, 'temporal_without_pool.yaml', ['temporal.default.worker' => null]];
        yield 'default logger with monolog' => [true, 'temporal_without_pool.yaml', ['temporal.default.worker' => 'monolog.logger.temporal']];
    }


    /**
     * @param non-empty-string                             $configFile
     * @param array<non-empty-string, ?non-empty-string>   $expected
     */
    #[DataProvider('workerLoggerDataProvider')]
    public function testRegisterWorkerLogger(bool $monolog, string $configFile, array $expected): void
    {
        $loggers = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($monolog, $configFile, $expected, &$loggers): void {
            if ($monolog) {
                $kernel->addTestBundle(MonologBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/monolog.yaml');
            }

            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/' . $configFile);

            $kernel->addTestCompilerPass(new class(array_keys($expected), $loggers) implements CompilerPass {
                /**
                 * @param list<non-empty-string>  $ids
                 * @param array<string, mixed>    $loggers
                 */
                public function __construct(
                    private readonly array $ids,
                    public array &$loggers,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $container->register('app.global_logger', NullLogger::class);
                    $container->register('app.worker_logger', NullLogger::class);

                    foreach ($this->ids as $id) {
                        $logger             = $container->getDefinition($id)->getArgument(4);
                        $this->loggers[$id] = $logger instanceof Reference ? (string) $logger : $logger;
                    }
                }
            });
        }]);

        assertSame($expected, $loggers);
    }


    public function testRegisterWorkerWithCustomFactory(): void
    {
        $kernel = self::bootKernel([
            'config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_factory.yaml');
            },
        ]);

        $container = $kernel->getContainer();

        assertTrue($container->has('temporal.runtime'));

        /** @var Runtime|null $runtime */
        $runtime = $container->get('temporal.runtime');

        assertNotNull($runtime);
        assertInstanceOf(Runtime::class, $runtime);
        assertCount(3, $runtime);

        $factory = $container->get('temporal.worker_factory');
        assertInstanceOf(WorkerFactory::class, $factory);
    }

    public function testRegisterWorkerInTestingModeSharesOneFactory(): void
    {
        $kernel = self::bootKernel([
            'config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_testing.yaml');
            },
        ]);

        $container = $kernel->getContainer();

        $factory = $container->get('temporal.worker_factory');
        assertInstanceOf(WorkerFactory::class, $factory);

        /** @var Runtime $runtime */
        $runtime = $container->get('temporal.runtime');

        assertCount(2, $runtime);
        assertSame($factory, (new ReflectionProperty(Runtime::class, 'factory'))->getValue($runtime));
    }

    public function testRegisterWorkerWithMissingActivityMocker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Service "app.missing_activity_cache" of "temporal.pool.testing.activityMocker" not found');

        self::bootKernel([
            'config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_testing_missing_activity_mocker.yaml');
            },
        ]);
    }


    public function testRegisterWorkerWithInvalidCustomFactory(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "temporal.pool.workerFactory": workerFactory does not implement interface: Temporal\Worker\WorkerFactoryInterface');

        self::bootKernel([
            'config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_invalid_factory.yaml');
            },
        ]);
    }


    /**
     * @param non-empty-string $id
     * @param WorkerOptions    $options
     */
    #[DataProvider('registerWorkerOptionsDataProvider')]
    public function testRegisterWorkerOptions(string $id, array $options): void
    {
        $hasDefinition = false;
        $def           = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$def): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $def) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 */
                public function __construct(
                    private readonly string                                    $id,
                    public bool                                               &$hasDefinition,
                    public ?Definition                                        &$def,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition = $container->hasDefinition($this->id);
                    $argument            = $container->getDefinition($this->id)->getArgument(1);
                    $this->def           = $argument instanceof Definition ? $argument : null;
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertInstanceOf(Definition::class, $def);

        foreach ($def->getMethodCalls() as [$method, $arguments, $returnClone]) {
            assertArrayHasKey($method, $options);
            assertCount(1, $arguments);
            assertEquals([$options[$method]], $arguments, 'Invalid option ' . $method);
            assertTrue($returnClone);
        }
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: WorkerOptions}>
     */
    public static function registerWorkerOptionsDataProvider(): iterable
    {
        yield [
            'temporal.default.worker',
            [
                'withMaxConcurrentActivityExecutionSize'      => 0,
                'withWorkerActivitiesPerSecond'               => 0,
                'withMaxConcurrentLocalActivityExecutionSize' => 0,
                'withWorkerLocalActivitiesPerSecond'          => 0,
                'withTaskQueueActivitiesPerSecond'            => 0,
                'withMaxConcurrentActivityTaskPollers'        => 0,
                'withMaxConcurrentWorkflowTaskExecutionSize'  => 0,
                'withMaxConcurrentWorkflowTaskPollers'        => 0,
                'maxConcurrentEagerActivityExecutionSize'     => 0,
                'withMaxConcurrentSessionExecutionSize'       => 1000,
                'withMaxConcurrentEagerActivityExecutionSize' => 0,
                'withDisableRegistrationAliasing'             => false,
                'withEnableSessionWorker'                     => false,
                'withSessionResourceId'                       => null,
                'withStickyScheduleToStartTimeout'            => null,
                'withWorkerStopTimeout'                       => null,
                'withDeadlockDetectionTimeout'                => null,
                'withMaxHeartbeatThrottleInterval'            => null,
                'withBuildID'                                 => '',
                'withUseBuildIDForVersioning'                 => false,
                'withEnableLoggingInReplay'                   => false,
                'withDisableWorkflowWorker'                   => false,
                'withLocalActivityWorkerOnly'                 => false,
                'withDisableEagerActivities'                  => false,
                'withWorkflowPanicPolicy'                     => WorkflowPanicPolicy::BlockWorkflow,
            ],
        ];

        yield [
            'temporal.foo.worker',
            [
                'withMaxConcurrentActivityExecutionSize'      => 1,
                'withWorkerActivitiesPerSecond'               => 1,
                'withMaxConcurrentLocalActivityExecutionSize' => 1,
                'withWorkerLocalActivitiesPerSecond'          => 1,
                'withTaskQueueActivitiesPerSecond'            => 1,
                'withMaxConcurrentActivityTaskPollers'        => 1,
                'withMaxConcurrentWorkflowTaskExecutionSize'  => 1,
                'withMaxConcurrentWorkflowTaskPollers'        => 1,
                'maxConcurrentEagerActivityExecutionSize'     => 1,
                'withMaxConcurrentEagerActivityExecutionSize' => 1,
                'withMaxConcurrentSessionExecutionSize'       => 2000,
                'withEnableSessionWorker'                     => true,
                'withDisableRegistrationAliasing'             => true,
                'withSessionResourceId'                       => 'resource.foo',
                'withStickyScheduleToStartTimeout'            => '30 seconds',
                'withWorkerStopTimeout'                       => '30 seconds',
                'withDeadlockDetectionTimeout'                => '30 seconds',
                'withMaxHeartbeatThrottleInterval'            => '30 seconds',
                'withBuildID'                                 => 'test',
                'withIdentity'                                => 'foo-worker',
                'withMaxConcurrentNexusTaskExecutionSize'     => 3,
                'withMaxConcurrentNexusTaskPollers'           => 2,
                'withEnableLoggingInReplay'                   => true,
                'withDisableWorkflowWorker'                   => true,
                'withLocalActivityWorkerOnly'                 => true,
                'withDisableEagerActivities'                  => true,
                'withWorkflowPanicPolicy'                     => WorkflowPanicPolicy::FailWorkflow,
            ],

        ];


        yield [
            'temporal.bar.worker',
            [
                'withMaxConcurrentActivityExecutionSize'      => 2,
                'withWorkerActivitiesPerSecond'               => 2,
                'withMaxConcurrentLocalActivityExecutionSize' => 2,
                'withWorkerLocalActivitiesPerSecond'          => 2,
                'withTaskQueueActivitiesPerSecond'            => 2,
                'withMaxConcurrentActivityTaskPollers'        => 2,
                'withMaxConcurrentWorkflowTaskExecutionSize'  => 2,
                'withMaxConcurrentWorkflowTaskPollers'        => 2,
                'maxConcurrentEagerActivityExecutionSize'     => 2,
                'withMaxConcurrentEagerActivityExecutionSize' => 2,
                'withMaxConcurrentSessionExecutionSize'       => 3000,
                'withEnableSessionWorker'                     => false,
                'withDisableRegistrationAliasing'             => false,
                'withSessionResourceId'                       => 'resource.bar',
                'withBuildID'                                 => '',
                'withStickyScheduleToStartTimeout'            => null,
                'withWorkerStopTimeout'                       => null,
                'withDeadlockDetectionTimeout'                => null,
                'withMaxHeartbeatThrottleInterval'            => null,
                'withUseBuildIDForVersioning'                 => false,
                'withEnableLoggingInReplay'                   => false,
                'withDisableWorkflowWorker'                   => false,
                'withLocalActivityWorkerOnly'                 => false,
                'withDisableEagerActivities'                  => false,
                'withWorkflowPanicPolicy'                     => WorkflowPanicPolicy::BlockWorkflow,
                'withDeploymentOptions'                       => (new Definition(WorkerDeploymentOptions::class))
                    ->setFactory([WorkerDeploymentOptions::class, 'new'])
                    ->addMethodCall('withUseVersioning', [true], true)
                    ->addMethodCall('withDefaultVersioningBehavior', [VersioningBehavior::Pinned], true)
                    ->addMethodCall('withVersion', [
                        (new Definition(WorkerDeploymentVersion::class))
                            ->setFactory([WorkerDeploymentVersion::class, 'new'])
                            ->setArguments(['billing', '1.0.0']),
                    ], true),
            ],
        ];
    }


    /**
     * @param non-empty-string $id
     * @param non-empty-string $taskQueue
     */
    #[DataProvider('registerWorkerTaskQueueDataProvider')]
    public function testRegisterWorkerTaskQueue(string $id, string $taskQueue): void
    {
        $hasDefinition = false;
        $argument      = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$argument): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $argument) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 */
                public function __construct(
                    private readonly string $id,
                    public bool            &$hasDefinition,
                    public mixed           &$argument,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition = $container->hasDefinition($this->id);
                    $this->argument      = $container->getDefinition($this->id)->getArgument(0);
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertEquals($taskQueue, $argument);
    }


    /**
     * @return iterable<array<int, non-empty-string>>
     */
    public static function registerWorkerTaskQueueDataProvider(): iterable
    {
        yield ['temporal.default.worker', 'default'];
        yield ['temporal.foo.worker', 'foo'];
        yield ['temporal.bar.worker', 'bar'];
    }


    /**
     * @param non-empty-string                    $id
     * @param non-empty-array<int, class-string>  $workflows
     */
    #[DataProvider('registerWorkflowDataProvider')]
    public function testRegisterWorkflow(string $id, array $workflows): void
    {
        $hasDefinition = false;
        /** @var array<int, array{0: string, 1: array<int, mixed>, 2: bool}> $calls */
        $calls             = [];
        $taggedWorkflowIds = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$calls, &$taggedWorkflowIds): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestBundle(TestWorkflowBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $calls, $taggedWorkflowIds) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 * @param array<int, array{0: string, 1: array<int, mixed>, 2: bool}> $calls
                 */
                public function __construct(
                    private readonly string $id,
                    public bool            &$hasDefinition,
                    public mixed           &$calls,
                    public mixed           &$taggedWorkflowIds,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition     = $container->hasDefinition($this->id);
                    $this->calls             = $container->getDefinition($this->id)->getMethodCalls();
                    $this->taggedWorkflowIds = $container->findTaggedServiceIds('temporal.workflow');
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertNotEmpty($calls, 'Not found registered workflows');

        foreach ($calls as [$method, $arguments, $returnClone]) {
            if ($method === 'registerActivityFinalizer') {
                continue;
            }

            assertEquals('registerWorkflowTypes', $method);
            assertCount(1, $arguments);
            assertArrayHasKey(0, $arguments);
            assertContains($arguments[0], $workflows);
        }

        assertIsArray($taggedWorkflowIds);
        assertCount(0, $taggedWorkflowIds);
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: non-empty-array<int, class-string>}>
     */
    public static function registerWorkflowDataProvider(): iterable
    {
        yield ['temporal.default.worker', [NullWorkflowHandler::class]];
        yield ['temporal.foo.worker', [AssignWorkflowHandler::class]];
        yield ['temporal.bar.worker', [AssignWorkflowHandlerV2::class]];
    }


    /**
     * @param non-empty-string                    $id
     * @param non-empty-array<int, class-string>  $activity
     */
    #[DataProvider('registerActivityDataProvider')]
    public function testRegisterActivity(string $id, array $activity): void
    {
        $hasDefinition = false;
        /** @var array<int, array{0: string, 1: array<int, mixed>, 2: bool}> $calls */
        $calls = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$calls): void {
            $kernel->addTestBundle(TestActivityBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $calls) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 * @param array<int, array{0: string, 1: array<int, mixed>, 2: bool}> $calls
                 */
                public function __construct(
                    private readonly string $id,
                    public bool            &$hasDefinition,
                    public array           &$calls,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition = $container->hasDefinition($this->id);
                    $this->calls         = $container->getDefinition($this->id)->getMethodCalls();
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertNotEmpty($calls, 'Not found registered activity');

        foreach ($calls as [$method, $arguments, $returnClone]) {
            if ($method === 'registerActivityFinalizer') {
                continue;
            }

            assertEquals('registerActivity', $method);
            assertCount(2, $arguments);
            assertArrayHasKey(0, $arguments);
            assertContains($arguments[0], $activity);
            assertArrayHasKey(1, $arguments);
            assertIsString($arguments[0]);
            assertEquals(new ServiceClosureArgument(new Reference($arguments[0])), $arguments[1]);
        }
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: non-empty-array<int, class-string>}>
     */
    public static function registerActivityDataProvider(): iterable
    {
        // Activities without #[AssignWorker] run on the default worker only, see WorkerAssignmentTest
        yield ['temporal.default.worker', [ActivityAHandler::class, ActivityBHandler::class, ActivityCHandler::class]];
    }

    /**
     * @param non-empty-string                    $id
     * @param array{0: non-empty-string, 1: array{0: list<Reference>}}  $arguments
     */
    #[DataProvider('registerCustomFinalizers')]
    public function testRegisterCustomFinalizers(string $id, array $arguments): void
    {
        $hasDefinition   = false;
        $definitionClass = null;
        $actualArguments = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$definitionClass, &$actualArguments): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_custom_finalizers.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $definitionClass, $actualArguments) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 */
                public function __construct(
                    private readonly string $id,
                    public bool            &$hasDefinition,
                    public mixed           &$definitionClass,
                    public mixed           &$actualArguments,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition   = $container->hasDefinition($this->id);
                    $definition            = $container->getDefinition($this->id);
                    $this->definitionClass = $definition->getClass();
                    $this->actualArguments = unserialize(serialize($definition->getArguments()));
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertEquals(ChainFinalizer::class, $definitionClass);
        assertEquals($arguments, $actualArguments);
    }

    /**
     * @return iterable<array{0: non-empty-string, 1: array{0: list<Reference>}}>
     */
    public static function registerCustomFinalizers(): iterable
    {
        yield ['temporal.default.worker.finalizer', [
            [new Reference('test.temporal.finalizer.dummy1'), new Reference('test.temporal.finalizer.dummy2'), new Reference('temporal.framework.finalizer')],
            referenceLogger(),
        ]];
        yield ['temporal.foo.worker.finalizer', [
            [new Reference('test.temporal.finalizer.dummy2'), new Reference('temporal.framework.finalizer')],
            referenceLogger(),
        ]];
    }
}
