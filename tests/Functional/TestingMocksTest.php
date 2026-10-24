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
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertInstanceOf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(WorkflowCompilerPass::class)]
#[CoversClass(ClientCompilerPass::class)]
final class TestingMocksTest extends KernelTestCase
{
    private const WORKFLOW_MOCKER = 'Temporal\Testing\WorkflowMocker';


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


    public function testWorkflowMocks(): void
    {
        $pipeline = null;

        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel) use (&$pipeline): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_testing.yaml');

            $kernel->addTestCompilerPass(new class($pipeline) implements CompilerPass {
                public function __construct(
                    public mixed &$pipeline,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $provider       = $container->getDefinition('temporal.default.worker')->getArgument(3);
                    $this->pipeline = $provider instanceof Definition ? $provider->getArgument(0) : null;
                }
            });
        }]);

        $container = $kernel->getContainer();

        if (!SdkFeature::hasClass(self::WORKFLOW_MOCKER)) {
            assertFalse($container->has('temporal.testing.workflow_mocker'));

            return;
        }

        assertInstanceOf(self::WORKFLOW_MOCKER, $container->get('temporal.testing.workflow_mocker')); // @phpstan-ignore argument.type
        assertInstanceOf(self::WORKFLOW_MOCKER, $container->get(self::WORKFLOW_MOCKER)); // @phpstan-ignore argument.type
        assertInstanceOf('Temporal\Testing\SearchAttributeMocker', $container->get('temporal.testing.search_attribute_mocker')); // @phpstan-ignore argument.type

        self::assertIsArray($pipeline);
        assertEquals([
            new Reference('temporal.testing.mock_child_workflow.interceptor'),
            new Reference('temporal.testing.mock_side_effect.interceptor'),
            new Reference('temporal.testing.mock_search_attribute.interceptor'),
        ], array_slice($pipeline, 0, 3));
    }


    public function testTimeLocking(): void
    {
        if (!SdkFeature::hasClass('Temporal\Testing\TimeLockingInterceptor')) {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('Option "temporal.pool.testing.timeLocking" requires temporal/sdk >= 2.18');
        }

        $result = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$result): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_testing_time_locking.yaml');

            $kernel->addTestCompilerPass(new class($result) implements CompilerPass {
                /**
                 * @param array<string, mixed> $result
                 */
                public function __construct(
                    public array &$result,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $provider = $container->getDefinition('temporal.default.client')->getArgument('$interceptorProvider');

                    $this->result['pipeline']    = $provider instanceof Definition ? $provider->getArgument(0) : null;
                    $this->result['testService'] = $container->getDefinition('temporal.testing.time_locking.interceptor')->getArgument(0);
                }
            });
        }]);

        self::assertIsArray($result['pipeline']);
        assertEquals(new Reference('temporal.testing.time_locking.interceptor'), end($result['pipeline']));
        assertEquals(new Reference('temporal.testing.default.testService'), $result['testService']);
    }
}
