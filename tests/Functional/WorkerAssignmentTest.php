<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use ArrayObject;
use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityAHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\AssignedActivityHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\UnknownWorkerActivityHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\AssignWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\MultiWorkerWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\NullWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\NumericWorkerWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\UnknownWorkerWorkflowHandler;

/**
 * Which worker gets which workflow / activity, see #[AssignWorker].
 */
#[CoversClass(WorkflowCompilerPass::class)]
final class WorkerAssignmentTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): Kernel
    {
        /** @var TestKernel $kernel */
        $kernel = parent::createKernel($options);
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testUnknownWorkerOnWorkflowFailsTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            "#[AssignWorker('missing')] on %s refers to an unknown worker; configured workers: default, foo, bar",
            UnknownWorkerWorkflowHandler::class,
        ));

        $this->registrations([UnknownWorkerWorkflowHandler::class]);
    }

    public function testUnknownWorkerOnActivityFailsTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            "#[AssignWorker('missing')] on %s refers to an unknown worker; configured workers: default, foo, bar",
            UnknownWorkerActivityHandler::class,
        ));

        $this->registrations([UnknownWorkerActivityHandler::class]);
    }

    public function testUnassignedClassesGoToTheDefaultWorkerOnly(): void
    {
        $registrations = $this->registrations([NullWorkflowHandler::class, ActivityAHandler::class]);

        assertSame([NullWorkflowHandler::class, ActivityAHandler::class], $registrations['default']);
        assertSame([], $registrations['foo']);
        assertSame([], $registrations['bar']);
    }

    public function testAssignedClassesGoToTheirWorkersOnly(): void
    {
        $registrations = $this->registrations([AssignWorkflowHandler::class, AssignedActivityHandler::class]);

        assertSame([], $registrations['default']);
        assertSame([AssignWorkflowHandler::class, AssignedActivityHandler::class], $registrations['foo']);
        assertSame([], $registrations['bar']);
    }

    public function testRepeatedAttributeAssignsToEveryNamedWorker(): void
    {
        $registrations = $this->registrations([MultiWorkerWorkflowHandler::class]);

        assertSame([], $registrations['default']);
        assertSame([MultiWorkerWorkflowHandler::class], $registrations['foo']);
        assertSame([MultiWorkerWorkflowHandler::class], $registrations['bar']);
    }

    public function testCustomDefaultWorker(): void
    {
        $registrations = $this->registrations([NullWorkflowHandler::class], ['defaultWorker' => 'foo']);

        assertSame([], $registrations['default']);
        assertSame([NullWorkflowHandler::class], $registrations['foo']);
        assertSame([], $registrations['bar']);
    }

    public function testUnassignedClassesWithoutDefaultWorkerFailTheBuild(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Classes without #[AssignWorker] run on the default worker "default" (temporal.defaultWorker), which is not configured: %s; configured workers: foo',
            NullWorkflowHandler::class,
        ));

        $this->registrations([NullWorkflowHandler::class], workers: ['foo']);
    }

    public function testAssignedClassesWithoutDefaultWorkerBuild(): void
    {
        $registrations = $this->registrations([AssignWorkflowHandler::class], workers: ['foo']);

        assertSame(['foo' => [AssignWorkflowHandler::class]], $registrations);
    }

    public function testNumericWorkerName(): void
    {
        // PHP turns the worker key "2" into the integer 2
        $registrations = $this->registrations([NumericWorkerWorkflowHandler::class], workers: ['default', '2']);

        assertSame(['default' => [], 2 => [NumericWorkerWorkflowHandler::class]], $registrations);
    }

    public function testClassTaggedTwiceIsRegisteredOncePerWorker(): void
    {
        $registrations = $this->registrations([
            NullWorkflowHandler::class,
            'second.workflow.id' => NullWorkflowHandler::class,
            ActivityAHandler::class,
            'second.activity.id' => ActivityAHandler::class,
        ]);

        assertSame([NullWorkflowHandler::class, ActivityAHandler::class], $registrations['default']);
    }

    /**
     * @param array<array-key, class-string> $services service id (class name when the key is an integer) => class
     * @param array<string, mixed>           $config   overrides of the temporal configuration
     * @param list<non-empty-string>         $workers  configured workers, the task queue is the worker name
     *
     * @return array<string, list<class-string>> worker name => registered workflow / activity classes
     */
    private function registrations(array $services, array $config = [], array $workers = ['default', 'foo', 'bar']): array
    {
        /** @var ArrayObject<string, list<class-string>> $registrations */
        $registrations = new ArrayObject();

        $config = [
            'pool'    => ['dataConverter' => 'temporal.data_converter', 'roadrunnerRPC' => 'tcp://127.0.0.1:6001'],
            'workers' => array_combine($workers, array_map(self::worker(...), $workers)),
            ...$config,
        ];

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($services, $config, $workers, $registrations): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(static function (ContainerBuilder $container) use ($services, $config): void {
                $container->loadFromExtension('temporal', $config);

                foreach ($services as $id => $class) {
                    $container->register(is_int($id) ? $class : $id, $class)->setAutoconfigured(true);
                }
            });

            $kernel->addTestCompilerPass(new class($workers, $registrations) implements CompilerPass {
                /**
                 * @param list<string>                            $workers
                 * @param ArrayObject<string, list<class-string>> $registrations
                 */
                public function __construct(
                    private readonly array $workers,
                    private readonly ArrayObject $registrations,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    foreach ($this->workers as $worker) {
                        $classes = [];

                        foreach ($container->getDefinition(sprintf('temporal.%s.worker', $worker))->getMethodCalls() as [$method, $arguments]) {
                            if ($method == 'registerWorkflowTypes') {
                                $classes[] = $arguments[0];
                            }

                            if ($method == 'registerActivity') {
                                $classes[] = $container->getDefinition($arguments[0])->getClass();
                            }
                        }

                        /** @var list<class-string> $classes */
                        $this->registrations[$worker] = $classes;
                    }
                }
            });
        }]);

        return $registrations->getArrayCopy();
    }

    /**
     * @return array{taskQueue: string, exceptionInterceptor: string}
     */
    private static function worker(string $taskQueue): array
    {
        return ['taskQueue' => $taskQueue, 'exceptionInterceptor' => 'temporal.exception_interceptor'];
    }
}
