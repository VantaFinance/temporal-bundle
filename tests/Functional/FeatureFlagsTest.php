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

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Worker\FeatureFlags;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\Runtime\FeatureFlagsConfigurator;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(WorkflowCompilerPass::class)]
#[CoversClass(FeatureFlagsConfigurator::class)]
final class FeatureFlagsTest extends KernelTestCase
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


    protected function tearDown(): void
    {
        SdkFeature::setHandler(null);

        FeatureFlags::$warnOnWorkflowUnfinishedHandlers = true;
        FeatureFlags::$workflowDeferredHandlerStart     = false;

        parent::tearDown();
    }


    public function testFlagsAreSetWithWorkerFactory(): void
    {
        $kernel = $this->boot('temporal_with_feature_flags.yaml');

        // Untouched until the worker factory is created
        assertTrue(FeatureFlags::$warnOnWorkflowUnfinishedHandlers);

        $kernel->getContainer()->get('temporal.worker_factory');

        assertFalse(FeatureFlags::$warnOnWorkflowUnfinishedHandlers);
        assertTrue(FeatureFlags::$workflowDeferredHandlerStart);
        assertTrue(FeatureFlags::$cancelAbandonedChildWorkflows);
    }


    public function testSdk220Flag(): void
    {
        if (!SdkFeature::hasProperty(FeatureFlags::class, 'readOnlyWorkflowCallbacks')) {
            self::markTestSkipped('readOnlyWorkflowCallbacks requires temporal/sdk >= 2.20');
        }

        $this->boot('temporal_with_sdk_2_20_feature_flags.yaml')->getContainer()->get('temporal.worker_factory');

        assertFalse(FeatureFlags::${'readOnlyWorkflowCallbacks'}); // @phpstan-ignore staticProperty.notFound

        FeatureFlags::${'readOnlyWorkflowCallbacks'} = true; // @phpstan-ignore staticProperty.notFound
    }


    public function testFlagOnOlderSdkFails(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class, ?string $member): bool => $member != 'readOnlyWorkflowCallbacks');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.pool.featureFlags.readOnlyWorkflowCallbacks" requires temporal/sdk >= 2.20');

        $this->boot('temporal_with_sdk_2_20_feature_flags.yaml');
    }


    /**
     * @param non-empty-string $configFile
     */
    private function boot(string $configFile): Kernel
    {
        return self::bootKernel(['config' => static function (TestKernel $kernel) use ($configFile): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/' . $configFile);
        }]);
    }
}
