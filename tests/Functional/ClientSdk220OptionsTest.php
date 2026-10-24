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
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ScheduleClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[RunTestsInSeparateProcesses]
#[CoversClass(ClientCompilerPass::class)]
#[CoversClass(ScheduleClientCompilerPass::class)]
final class ClientSdk220OptionsTest extends KernelTestCase
{
    private const PAYLOAD_LIMIT_OPTIONS = 'Temporal\Common\PayloadLimitOptions';


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


    public function testPayloadLimitsAndLogger(): void
    {
        if (!SdkFeature::hasClass(self::PAYLOAD_LIMIT_OPTIONS)) {
            self::markTestSkipped('Payload limits and the client logger require temporal/sdk >= 2.20');
        }

        $definitions = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$definitions): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_client_sdk_2_20_options.yaml');

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
                    $this->definitions['client']         = clone $container->getDefinition('temporal.default.client');
                    $this->definitions['scheduleClient'] = clone $container->getDefinition('temporal.default.schedule_client');
                }
            });
        }]);

        assertEquals(new Reference('app.logger'), $definitions['client']->getArgument('$logger'));

        $limits = $this->payloadLimits($definitions['client']);

        assertEquals([self::PAYLOAD_LIMIT_OPTIONS, 'new'], $limits->getFactory());
        assertEquals([['withPayloadSizeWarning', [1024], true]], $limits->getMethodCalls());

        assertEquals([self::PAYLOAD_LIMIT_OPTIONS, 'disabled'], $this->payloadLimits($definitions['scheduleClient'])->getFactory());
    }


    public function testPayloadLimitsRequireSdk220(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class): bool => $class != self::PAYLOAD_LIMIT_OPTIONS);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.clients.default.payloadLimits" requires temporal/sdk >= 2.20');

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_client_sdk_2_20_options.yaml');
        }]);
    }


    public function testLoggerRequiresSdk220(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class, ?string $member, ?string $parameter): bool => $parameter != 'logger');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.clients.default.logger" requires temporal/sdk >= 2.20');

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_client_sdk_2_20_options.yaml');
        }]);
    }


    private function payloadLimits(Definition $client): Definition
    {
        $options = $client->getArgument('$options');

        assertInstanceOf(Definition::class, $options);

        foreach ($options->getMethodCalls() as [$method, $arguments]) {
            if ($method == 'withPayloadLimits' && $arguments[0] instanceof Definition) {
                return $arguments[0];
            }
        }

        self::fail('withPayloadLimits() is not called');
    }
}
