<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use DomainException;
use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Exception\ExceptionInterceptor;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(WorkflowCompilerPass::class)]
final class WorkerPoolOptionsTest extends KernelTestCase
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


    public function testPoolOptions(): void
    {
        $definitions = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$definitions): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_pool_options.yaml');

            $kernel->addTestCompilerPass(new class($definitions) implements CompilerPass {
                /**
                 * @param array<string, Definition> $definitions
                 */
                public function __construct(
                    public array &$definitions,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    foreach (['temporal.worker_factory', 'temporal.default.worker', 'temporal.testing.rr_kv.activity_cache'] as $id) {
                        $this->definitions[$id] = clone $container->getDefinition($id);
                    }
                }
            });
        }]);

        assertEquals(new Reference('app.rpc'), $definitions['temporal.worker_factory']->getArgument('$rpc'));

        $worker = $definitions['temporal.default.worker'];

        assertEquals(new Reference('app.pipeline_provider'), $worker->getArgument(3));

        $exceptionInterceptor = $worker->getArgument(2);

        assertInstanceOf(Definition::class, $exceptionInterceptor);
        assertEquals(ExceptionInterceptor::class, $exceptionInterceptor->getClass());
        assertEquals([[DomainException::class]], $exceptionInterceptor->getArguments());

        assertEquals('custom_kv', $definitions['temporal.testing.rr_kv.activity_cache']->getArgument(1));
    }


    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidPoolDataProvider(): iterable
    {
        yield 'pipeline provider with bundle interceptors' => [
            'temporal_with_pipeline_provider_and_interceptors.yaml',
            'temporal.pool.pipelineProvider "app.pipeline_provider" replaces the bundle interceptor pipeline, so these would be ignored: temporal.workers.default interceptors / integrations',
        ];

        yield 'custom factory without activity cache' => [
            'temporal_testing_with_sdk_factory.yaml',
            'Worker factory Temporal\WorkerFactory (temporal.pool.workerFactory) has no $activityCache parameter in create()',
        ];

        yield 'activity mocker without the interface' => [
            'temporal_testing_invalid_activity_mocker.yaml',
            'Service "app.activity_cache" of "temporal.pool.testing.activityMocker" (Temporal\Interceptor\SimplePipelineProvider) must implement Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface',
        ];
    }


    /**
     * @param non-empty-string $configFile
     * @param non-empty-string $message
     */
    #[DataProvider('invalidPoolDataProvider')]
    public function testInvalidPool(string $configFile, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($configFile): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/' . $configFile);
        }]);
    }
}
