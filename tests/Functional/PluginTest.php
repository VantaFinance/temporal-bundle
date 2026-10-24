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
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\PluginCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Plugin\GlobalPlugin;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Plugin\ScopedPlugin;

#[RunTestsInSeparateProcesses]
#[CoversClass(PluginCompilerPass::class)]
final class PluginTest extends KernelTestCase
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


    public function testPluginRegistries(): void
    {
        if (!interface_exists(PluginCompilerPass::PLUGIN_INTERFACE)) {
            self::markTestSkipped('Plugins require temporal/sdk >= 2.18');
        }

        $arguments = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$arguments): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_plugins.yaml');

            $kernel->addTestCompilerPass(new class($arguments) implements CompilerPass {
                /**
                 * @param array<string, mixed> $arguments
                 */
                public function __construct(
                    public array &$arguments,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $registry = static function (Definition $definition): mixed {
                        $registry = $definition->getArguments()['$pluginRegistry'] ?? null;

                        return $registry instanceof Definition ? $registry->getArgument(0) : null;
                    };

                    $factory = $container->getDefinition('temporal.worker_factory');

                    $this->arguments = [
                        'default'             => $registry($container->getDefinition('temporal.default.client')),
                        'foo'                 => $registry($container->getDefinition('temporal.foo.client')),
                        'schedule'            => $registry($container->getDefinition('temporal.default.schedule_client')),
                        'workers'             => $registry($factory),
                        'workerFactoryClient' => $factory->getArguments()['$client'] ?? null,
                    ];
                }
            });
        }]);

        $global = new Reference(GlobalPlugin::class);
        $scoped = new Reference(ScopedPlugin::class);

        assertEquals([$global, new Reference('app.client_plugin')], $arguments['default']);
        assertEquals([$global, $scoped], $arguments['foo']);
        assertEquals([$global], $arguments['schedule']);
        // The plugins of temporal.pool.client reach the workers through the client
        assertEquals([$scoped], $arguments['workers']);
        assertEquals(new Reference('temporal.default.client'), $arguments['workerFactoryClient']);
    }


    public function testPluginsRequireSdk218(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class): bool => $class != 'Temporal\Plugin\PluginRegistry');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('requires temporal/sdk >= 2.18');

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_plugin_service.yaml');
        }]);
    }


    public function testTranscriptPlugin(): void
    {
        if (!class_exists('Temporal\Testing\Transcript\TranscriptPlugin')) {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('Option "temporal.pool.testing.transcript" requires temporal/sdk >= 2.18');
        }

        $result = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$result): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_testing_transcript.yaml');

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
                    $registry = $container->getDefinition('temporal.worker_factory')->getArguments()['$pluginRegistry'] ?? null;

                    $this->result['plugins'] = $registry instanceof Definition ? $registry->getArgument(0) : null;
                    $this->result['path']    = $container->getDefinition('temporal.testing.transcript_writer')->getArgument(0);
                }
            });
        }]);

        assertEquals([new Reference('temporal.testing.transcript_plugin')], $result['plugins']);
        self::assertStringEndsWith('/var/log/transcript.log', (string) $result['path']); // @phpstan-ignore cast.string
    }
}
