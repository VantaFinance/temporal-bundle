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

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotEmpty;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[CoversClass(WorkflowCompilerPass::class)]
#[CoversClass(SdkFeature::class)]
final class WorkerOptionsMappingTest extends KernelTestCase
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

        parent::tearDown();
    }


    public function testEveryWorkerKeyHasWorkerOptionsMethod(): void
    {
        /** @var array{workers: array{default: array<string, mixed>}} $config */
        $config = (new Processor())->processConfiguration(new Configuration([], []), [
            ['workers' => ['default' => ['taskQueue' => 'default']]],
        ]);

        $keys = array_diff(array_keys($config['workers']['default']), WorkflowCompilerPass::BUNDLE_ONLY_WORKER_KEYS);

        assertNotEmpty($keys);

        foreach ($keys as $key) {
            if (array_key_exists($key, WorkflowCompilerPass::WORKER_OPTION_SINCE)) {
                continue;
            }

            assertTrue(
                method_exists(WorkerOptions::class, sprintf('with%s', ucfirst($key))),
                sprintf('Worker key "%s" has no %s::with%s() method; add it to BUNDLE_ONLY_WORKER_KEYS or WORKER_OPTION_SINCE', $key, WorkerOptions::class, ucfirst($key)),
            );
        }
    }


    public function testSdk220Options(): void
    {
        if (!SdkFeature::hasMethod(WorkerOptions::class, 'withDisablePayloadErrorLimit')) {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('Option "temporal.workers.default.disablePayloadErrorLimit" requires temporal/sdk >= 2.20');
        }

        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_sdk_2_20_worker_options.yaml');
        }]);

        $worker = $kernel->getContainer()->get('temporal.default.worker');

        assertInstanceOf(WorkerInterface::class, $worker);

        /** @phpstan-ignore property.notFound */
        assertTrue($worker->getOptions()->disablePayloadErrorLimit);
        /** @phpstan-ignore property.notFound */
        assertSame(2, $worker->getOptions()->maxEagerActivityReservationsPerWorkflowTask);
    }


    public function testConfiguredOptionMissingInSdkFailsCompilation(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class, ?string $member): bool => $member != 'withDisableRegistrationAliasing');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.workers.default.disableRegistrationAliasing" requires temporal/sdk >= 2.16');

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
        }]);
    }
}
