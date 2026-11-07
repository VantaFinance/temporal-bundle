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

use function PHPUnit\Framework\assertEmpty;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Interceptor\SimplePipelineProvider;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\SentryCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\TemporalExtension;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[CoversClass(TemporalExtension::class)]
#[CoversClass(SentryCompilerPass::class)]
final class SentryTest extends KernelTestCase
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


    /**
     * @param non-empty-string $id
     */
    #[DataProvider('notFoundHubDataProvider')]
    public function testNotFoundHub(string $id): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['sentry/sentry-symfony', 'vanta/temporal-sentry']);
        });

        $hasDefinition = true;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition): void {
            $kernel->addTestBundle(MonologBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/monolog.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 */
                public function __construct(
                    private readonly string $id,
                    public bool &$hasDefinition,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition = $container->hasDefinition($this->id);
                }
            });
        }]);

        assertFalse($hasDefinition);
    }


    /**
     * @return iterable<array<int, non-empty-string>>
     */
    public static function notFoundHubDataProvider(): iterable
    {
        yield ['temporal.sentry_default.interceptor'];
        yield ['temporal.sentry_foo.interceptor'];
        yield ['temporal.sentry_bar.interceptor'];
    }


    public function testRegisterTemporalInspector(): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['sentry/sentry-symfony', 'vanta/temporal-sentry']);
        });

        $hasActivityInterceptor         = false;
        $hasWorkflowOutboundInterceptor = false;
        $hasStackTraceBuilder           = false;

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$hasActivityInterceptor, &$hasWorkflowOutboundInterceptor, &$hasStackTraceBuilder): void {
            $kernel->addTestBundle(SentryBundle::class);
            $kernel->addTestBundle(MonologBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/sentry.yaml');

            $kernel->addTestCompilerPass(new class($hasActivityInterceptor, $hasWorkflowOutboundInterceptor, $hasStackTraceBuilder) implements CompilerPass {
                public function __construct(
                    public bool &$hasActivityInterceptor,
                    public bool &$hasWorkflowOutboundInterceptor,
                    public bool &$hasStackTraceBuilder,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasActivityInterceptor         = $container->hasDefinition('temporal.sentry_activity_inbound.interceptor');
                    $this->hasWorkflowOutboundInterceptor = $container->hasDefinition('temporal.sentry_workflow_outbound_calls.interceptor');
                    $this->hasStackTraceBuilder           = $container->hasDefinition('temporal.sentry_stack_trace_builder');
                }
            });
        }]);

        assertTrue($hasActivityInterceptor);
        assertTrue($hasWorkflowOutboundInterceptor);
        assertTrue($hasStackTraceBuilder);
    }


    public function testRegisterSentryIntegrationForSpecificWorker(): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['sentry/sentry-symfony', 'vanta/temporal-sentry']);
        });

        $sentryUseWorkerPipeline     = new Definition();
        $withoutSentryWorkerPipeline = new Definition();

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$sentryUseWorkerPipeline, &$withoutSentryWorkerPipeline): void {
            $kernel->addTestBundle(SentryBundle::class);
            $kernel->addTestBundle(MonologBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_use_specific_sentry_worker.yaml');


            $kernel->addTestCompilerPass(new class($sentryUseWorkerPipeline, $withoutSentryWorkerPipeline) implements CompilerPass {
                public function __construct(
                    public mixed &$sentryUseWorkerPipeline,
                    public mixed &$withoutSentryWorkerPipeline
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->sentryUseWorkerPipeline     = $container->getDefinition('temporal.sentry_use.worker')->getArgument(3);
                    $this->withoutSentryWorkerPipeline = $container->getDefinition('temporal.without_sentry.worker')->getArgument(3);
                }
            });
        }]);

        assertInstanceOf(Definition::class, $sentryUseWorkerPipeline);
        assertEquals(SimplePipelineProvider::class, $sentryUseWorkerPipeline->getClass());
        assertEquals([
            new Reference('temporal.sentry_workflow_outbound_calls.interceptor'),
            new Reference('temporal.sentry_activity_inbound.interceptor'),
        ], $sentryUseWorkerPipeline->getArgument(0));


        assertInstanceOf(Definition::class, $withoutSentryWorkerPipeline);
        assertEquals(SimplePipelineProvider::class, $withoutSentryWorkerPipeline->getClass());
        assertEmpty($withoutSentryWorkerPipeline->getArgument(0));

    }


    public function testRegisterSentryIntegrationForAllWorker(): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['sentry/sentry-symfony', 'vanta/temporal-sentry']);
        });

        $sentryUseWorkerPipeline     = new Definition();
        $withoutSentryWorkerPipeline = new Definition();

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$sentryUseWorkerPipeline, &$withoutSentryWorkerPipeline): void {
            $kernel->addTestBundle(SentryBundle::class);
            $kernel->addTestBundle(MonologBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_use_global_sentry.yaml');


            $kernel->addTestCompilerPass(new class($sentryUseWorkerPipeline, $withoutSentryWorkerPipeline) implements CompilerPass {
                public function __construct(
                    public mixed &$sentryUseWorkerPipeline,
                    public mixed &$withoutSentryWorkerPipeline
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    $this->sentryUseWorkerPipeline     = $container->getDefinition('temporal.sentry_use.worker')->getArgument(3);
                    $this->withoutSentryWorkerPipeline = $container->getDefinition('temporal.without_sentry.worker')->getArgument(3);
                }
            });
        }]);

        $expectedIntegrations = [
            new Reference('temporal.sentry_workflow_outbound_calls.interceptor'),
            new Reference('temporal.sentry_activity_inbound.interceptor'),
        ];


        assertInstanceOf(Definition::class, $sentryUseWorkerPipeline);
        assertEquals(SimplePipelineProvider::class, $sentryUseWorkerPipeline->getClass());
        assertEquals($expectedIntegrations, $sentryUseWorkerPipeline->getArgument(0));


        assertInstanceOf(Definition::class, $withoutSentryWorkerPipeline);
        assertEquals(SimplePipelineProvider::class, $withoutSentryWorkerPipeline->getClass());
        assertEquals($expectedIntegrations, $withoutSentryWorkerPipeline->getArgument(0));
    }
}
