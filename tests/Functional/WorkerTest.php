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
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;

use Symfony\Component\DependencyInjection\ContainerBuilder;

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
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
        assertInstanceOf(\Temporal\Testing\WorkerFactory::class, $factory);
    }


    /**
     * @param non-empty-string $id
     * @param WorkerOptions    $options
     */
    #[DataProvider('registerWorkerOptionsDataProvider')]
    public function testRegisterWorkerOptions(string $id, array $options): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, $options): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');


            $kernel->addTestCompilerPass(new class($id, $options) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 * @param array{
                 *    withMaxConcurrentActivityExecutionSize: int,
                 *    withWorkerActivitiesPerSecond: int,
                 *    withMaxConcurrentLocalActivityExecutionSize: int,
                 *    withWorkerLocalActivitiesPerSecond: int,
                 *    withTaskQueueActivitiesPerSecond: int,
                 *    withMaxConcurrentActivityTaskPollers: int,
                 *    withMaxConcurrentWorkflowTaskExecutionSize: int,
                 *    withMaxConcurrentWorkflowTaskPollers: int,
                 *    withEnableSessionWorker: bool,
                 *    withSessionResourceId: ?non-empty-string,
                 *    withMaxConcurrentSessionExecutionSize: int,
                 *  } $options
                 */
                public function __construct(
                    private readonly string $id,
                    private readonly array $options,
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));

                    /** @var Definition $def */
                    $def = $container->getDefinition($this->id)
                        ->getArgument(1)
                    ;

                    assertInstanceOf(Definition::class, $def);

                    foreach ($def->getMethodCalls() as [$method, $arguments, $returnClone]) {
                        assertArrayHasKey($method, $this->options);
                        assertCount(1, $arguments);
                        assertEquals([$this->options[$method]], $arguments);
                        assertTrue($returnClone);
                    }
                }
            });
        }]);
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
                'withEnableSessionWorker'                     => false,
                'withSessionResourceId'                       => null,
                'withMaxConcurrentSessionExecutionSize'       => 1000,
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
                'withEnableSessionWorker'                     => true,
                'withSessionResourceId'                       => 'resource.foo',
                'withMaxConcurrentSessionExecutionSize'       => 2000,
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
                'withEnableSessionWorker'                     => false,
                'withSessionResourceId'                       => 'resource.bar',
                'withMaxConcurrentSessionExecutionSize'       => 3000,
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
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, $taskQueue): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');


            $kernel->addTestCompilerPass(new class($id, $taskQueue) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 * @param non-empty-string $taskQueue
                 */
                public function __construct(
                    private readonly string $id,
                    private readonly string $taskQueue,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));
                    assertEquals($this->taskQueue, $container->getDefinition($this->id)->getArgument(0));
                }
            });
        }]);
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
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, $workflows): void {
            $kernel->addTestBundle(TestWorkflowBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $workflows) implements CompilerPass {
                /**
                 * @param non-empty-string                    $id
                 * @param non-empty-array<int, class-string>  $workflows
                 */
                public function __construct(
                    private readonly string $id,
                    private readonly array $workflows,
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));

                    $calls = $container->getDefinition($this->id)
                        ->getMethodCalls()
                    ;

                    foreach ($calls as [$method, $arguments, $returnClone]) {
                        assertEquals('registerWorkflowTypes', $method);
                        assertCount(1, $arguments);
                        assertArrayHasKey(0, $arguments);
                        assertContains($arguments[0], $this->workflows);
                    }


                    assertCount(0, $container->findTaggedServiceIds('temporal.workflow'));
                }
            });
        }]);
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: non-empty-array<int, class-string>}>
     */
    public static function registerWorkflowDataProvider(): iterable
    {
        yield ['temporal.default.worker', [NullWorkflowHandler::class]];
        yield ['temporal.foo.worker', [AssignWorkflowHandler::class, NullWorkflowHandler::class]];
        yield ['temporal.bar.worker', [AssignWorkflowHandlerV2::class, NullWorkflowHandler::class]];
    }


    /**
     * @param non-empty-string                    $id
     * @param non-empty-array<int, class-string>  $activity
     */
    #[DataProvider('registerActivityDataProvider')]
    public function testRegisterActivity(string $id, array $activity): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, $activity): void {
            $kernel->addTestBundle(TestActivityBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $activity) implements CompilerPass {
                /**
                 * @param non-empty-string                   $id
                 * @param non-empty-array<int, class-string> $activity
                 */
                public function __construct(
                    private readonly string $id,
                    private readonly array $activity,
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));

                    $calls = $container->getDefinition($this->id)
                        ->getMethodCalls()
                    ;

                    foreach ($calls as [$method, $arguments, $returnClone]) {
                        assertEquals('registerActivity', $method);
                        assertCount(2, $arguments);
                        assertArrayHasKey(0, $arguments);
                        assertContains($arguments[0], $this->activity);
                        assertArrayHasKey(1, $arguments);
                        assertEquals(new ServiceClosureArgument(new Reference($arguments[0])), $arguments[1]);
                    }
                }
            });
        }]);
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: non-empty-array<int, class-string>}>
     */
    public static function registerActivityDataProvider(): iterable
    {
        yield ['temporal.default.worker', [ActivityAHandler::class, ActivityBHandler::class, ActivityCHandler::class]];
        yield ['temporal.foo.worker', [ActivityAHandler::class, ActivityBHandler::class, ActivityCHandler::class]];
        yield ['temporal.bar.worker', [ActivityAHandler::class, ActivityBHandler::class, ActivityCHandler::class]];
    }
}
