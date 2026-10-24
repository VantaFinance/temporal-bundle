<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertEquals;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ScheduleClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(ClientCompilerPass::class)]
#[CoversClass(ScheduleClientCompilerPass::class)]
#[CoversClass(WorkflowCompilerPass::class)]
final class GrpcInterceptorTest extends KernelTestCase
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


    public function testGrpcInterceptorsGoToServiceClients(): void
    {
        $pipelines = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$pipelines): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_grpc_interceptors.yaml');

            $kernel->addTestCompilerPass(new class($pipelines) implements CompilerPass {
                /**
                 * @param array<string, mixed> $pipelines
                 */
                public function __construct(
                    public array &$pipelines,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->pipelines['service_client']          = $container->getDefinition('temporal.default.service_client')->getArgument('$interceptors');
                    $this->pipelines['schedule_service_client'] = $container->getDefinition('temporal.default.schedule_service_client')->getArgument('$interceptors');

                    $client = $container->getDefinition('temporal.default.client')->getArgument('$interceptorProvider');
                    $worker = $container->getDefinition('temporal.default.worker')->getArgument(3);

                    $this->pipelines['client'] = $client instanceof Definition ? $client->getArgument(0) : null;
                    $this->pipelines['worker'] = $worker instanceof Definition ? $worker->getArgument(0) : null;
                }
            });
        }]);

        $grpc = [new Reference('app.grpc_interceptor_child'), new Reference('app.grpc_interceptor')];

        assertEquals($grpc, $pipelines['service_client']);
        assertEquals($grpc, $pipelines['schedule_service_client']);
        assertEquals([new Reference('temporal.workflow_client_collector_default.interceptor'), new Reference('app.workflow_interceptor')], $pipelines['client']);
        assertEquals([new Reference('app.workflow_interceptor')], $pipelines['worker']);
    }


    public function testScheduleClientRejectsWorkflowInterceptor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Interceptor "app.workflow_interceptor" of schedule client "default" (temporal.scheduleClients.default.interceptors) must implement Temporal\Interceptor\GrpcClientInterceptor');

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_invalid_schedule_interceptor.yaml');
        }]);
    }
}
