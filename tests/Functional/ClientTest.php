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
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Client\ActivityCompletionClientInterface;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

/**
 * @phpstan-type ClientOptions array{
 *   withNamespace: non-empty-string,
 *   withIdentity: non-empty-string,
 *   withQueryRejectionCondition: int
 * }
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ClientCompilerPass::class)]
final class ClientTest extends KernelTestCase
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



    public function testRegisterClientCount(): void
    {
        $hasDefault = false;
        $hasFoo     = false;
        $hasBar     = false;
        $hasCloud   = false;

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$hasDefault, &$hasFoo, &$hasBar, &$hasCloud): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($hasDefault, $hasFoo, $hasBar, $hasCloud) implements CompilerPass {
                public function __construct(
                    public bool &$hasDefault,
                    public bool &$hasFoo,
                    public bool &$hasBar,
                    public bool &$hasCloud,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefault = $container->has('temporal.default.client');
                    $this->hasFoo     = $container->has('temporal.foo.client');
                    $this->hasBar     = $container->has('temporal.bar.client');
                    $this->hasCloud   = $container->has('temporal.cloud.client');
                }
            });
        }]);

        assertTrue($hasDefault);
        assertTrue($hasFoo);
        assertTrue($hasBar);
        assertTrue($hasCloud);
    }


    public function testRegisterClientAliasForArgument(): void
    {
        $hasDefault = false;
        $hasFoo     = false;
        $hasBar     = false;
        $hasCloud   = false;

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$hasDefault, &$hasFoo, &$hasBar, &$hasCloud): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($hasDefault, $hasFoo, $hasBar, $hasCloud) implements CompilerPass {
                public function __construct(
                    public bool &$hasDefault,
                    public bool &$hasFoo,
                    public bool &$hasBar,
                    public bool &$hasCloud,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefault = $container->hasAlias('Temporal\Client\WorkflowClientInterface $defaultWorkflowClient');
                    $this->hasFoo     = $container->hasAlias('Temporal\Client\WorkflowClientInterface $fooWorkflowClient');
                    $this->hasBar     = $container->hasAlias('Temporal\Client\WorkflowClientInterface $barWorkflowClient');
                    $this->hasCloud   = $container->hasAlias('Temporal\Client\WorkflowClientInterface $cloudWorkflowClient');
                }
            });
        }]);

        assertTrue($hasDefault);
        assertTrue($hasFoo);
        assertTrue($hasBar);
        assertTrue($hasCloud);
    }


    /**
     * @param non-empty-string $id
     * @param ClientOptions $options
     */
    #[DataProvider('registerClientOptionsDataProvider')]
    public function testRegisterClientOptions(string $id, array $options): void
    {
        $hasDefinition = false;
        $def           = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, &$hasDefinition, &$def): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($id, $hasDefinition, $def) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 */
                public function __construct(
                    private readonly string $id,
                    public bool            &$hasDefinition,
                    public ?Definition     &$def,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasDefinition = $container->hasDefinition($this->id);
                    $argument            = $container->getDefinition($this->id)->getArgument('$options');
                    $this->def           = $argument instanceof Definition ? $argument : null;
                }
            });
        }]);

        assertTrue($hasDefinition);
        assertInstanceOf(Definition::class, $def);

        foreach ($def->getMethodCalls() as [$method, $arguments, $returnClone]) {
            assertArrayHasKey($method, $options);
            assertCount(1, $arguments);
            assertEquals([$options[$method]], $arguments);
            assertTrue($returnClone);
        }
    }

    public function testImplicitDefaultClientUsesSdkRetryDefaults(): void
    {
        $context = null;

        self::bootKernel([
            'config' => static function (TestKernel $kernel) use (&$context): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_without_pool.yaml');

                $kernel->addTestCompilerPass(new class($context) implements CompilerPass {
                    public function __construct(
                        public ?Definition &$context,
                    ) {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        foreach ($container->getDefinition('temporal.default.service_client')->getMethodCalls() as [$method, $arguments]) {
                            if ($method == 'withContext' && $arguments[0] instanceof Definition) {
                                $this->context = clone $arguments[0];
                            }
                        }
                    }
                });
            },
        ]);

        assertInstanceOf(Definition::class, $context);
        assertEquals([Context::class, 'default'], $context->getFactory());
        // No withMetadata() / withRetryOptions(): the SDK defaults stay
        assertEquals([['withTimeout', [5, 'seconds'], true]], $context->getMethodCalls());
    }


    public function testRegisterActivityCompletionAndOperatorClients(): void
    {
        $result = [];

        self::bootKernel([
            'config' => static function (TestKernel $kernel) use (&$result): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

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
                        $completion = $container->getDefinition('temporal.foo.activity_completion_client');

                        $this->result['completionFactory']  = $completion->getFactory();
                        $this->result['completionAlias']    = (string) $container->getAlias(ActivityCompletionClientInterface::class);
                        $this->result['completionArgument'] = $container->hasAlias(ActivityCompletionClientInterface::class . ' $fooActivityCompletionClient');

                        if (!$container->hasDefinition('temporal.foo.operator_client')) {
                            return;
                        }

                        $operator = $container->getDefinition('temporal.foo.operator_client');

                        $this->result['operatorFactory']  = $operator->getFactory();
                        $this->result['operatorAddress']  = $operator->getArgument(0);
                        $this->result['operatorAlias']    = (string) $container->getAlias('Temporal\Client\GRPC\OperatorClientInterface');
                        $this->result['operatorArgument'] = $container->hasAlias('Temporal\Client\GRPC\OperatorClientInterface $fooOperatorClient');
                    }
                });
            },
        ]);

        assertEquals([new Reference('temporal.foo.client'), 'newActivityCompletionClient'], $result['completionFactory']);
        assertEquals('temporal.bar.activity_completion_client', $result['completionAlias']);
        assertTrue($result['completionArgument']);

        if (!class_exists('Temporal\Client\GRPC\OperatorClient')) {
            assertArrayNotHasKey('operatorFactory', $result);

            return;
        }

        assertEquals(['Temporal\Client\GRPC\OperatorClient', 'create'], $result['operatorFactory']);
        assertStringContainsString('TEMPORAL_ADDRESS', (string) $result['operatorAddress']); // @phpstan-ignore cast.string
        assertEquals('temporal.bar.operator_client', $result['operatorAlias']);
        assertTrue($result['operatorArgument']);
    }


    public function testRegisterCloudClient(): void
    {
        if (!class_exists('Temporal\Client\GRPC\CloudClient')) {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('Option "temporal.cloudClient" requires temporal/sdk >= 2.19');
        }

        $cloudClient = null;

        self::bootKernel([
            'config' => static function (TestKernel $kernel) use (&$cloudClient): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_cloud_client.yaml');

                $kernel->addTestCompilerPass(new class($cloudClient) implements CompilerPass {
                    public function __construct(
                        public ?Definition &$cloudClient,
                    ) {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $this->cloudClient = clone $container->getDefinition('temporal.cloud_client');
                    }
                });
            },
        ]);

        assertInstanceOf(Definition::class, $cloudClient);
        assertEquals(['Temporal\Client\GRPC\CloudClient', 'createSSL'], $cloudClient->getFactory());
        assertEquals(['saas-api.tmprl.cloud:443'], $cloudClient->getArguments());
        assertEquals(['withAuthKey', 'withApiVersion'], array_column($cloudClient->getMethodCalls(), 0));
        assertEquals(['2024-10-01-00'], $cloudClient->getMethodCalls()[1][1]);
    }


    public function testRegisterEnvConfigClient(): void
    {
        if (!class_exists('Temporal\Common\EnvConfig\ConfigClient')) {
            self::markTestSkipped('Environment configuration requires temporal/sdk >= 2.17');
        }

        $serviceClient  = null;
        $options        = null;
        $scheduleClient = null;

        self::bootKernel([
            'config' => static function (TestKernel $kernel) use (&$serviceClient, &$options, &$scheduleClient): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_env_config.yaml');

                $kernel->addTestCompilerPass(new class($serviceClient, $options, $scheduleClient) implements CompilerPass {
                    public function __construct(
                        public ?Definition &$serviceClient,
                        public ?Definition &$options,
                        public ?Definition &$scheduleClient,
                    ) {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $options = $container->getDefinition('temporal.default.client')->getArgument('$options');

                        $this->serviceClient  = clone $container->getDefinition('temporal.default.service_client');
                        $this->options        = $options instanceof Definition ? clone $options : null;
                        $this->scheduleClient = clone $container->getDefinition('temporal.default.schedule_service_client');
                    }
                });
            },
        ]);

        assertInstanceOf(Definition::class, $serviceClient);
        $factory = $serviceClient->getFactory();

        self::assertIsArray($factory);
        assertInstanceOf(Definition::class, $factory[0]);
        assertEquals(['Temporal\Common\EnvConfig\ConfigClient', 'load'], $factory[0]->getFactory());
        assertEquals('prod', $factory[0]->getArgument(0));
        assertEquals('toServiceClient', $factory[1]);

        assertInstanceOf(Definition::class, $options);
        $factory = $options->getFactory();

        self::assertIsArray($factory);
        assertEquals('toClientOptions', $factory[1]);
        // The profile namespace stays: no withNamespace()
        assertEquals([], array_filter($options->getMethodCalls(), static fn (array $call): bool => $call[0] == 'withNamespace'));

        assertInstanceOf(Definition::class, $scheduleClient);
        $factory = $scheduleClient->getFactory();

        self::assertIsArray($factory);
        assertEquals('toServiceClient', $factory[1]);
    }


    public function testEnvConfigRequiresSdk217(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class): bool => $class != 'Temporal\Common\EnvConfig\ConfigClient');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.clients.default.envConfig" requires temporal/sdk >= 2.17.0');

        try {
            self::bootKernel(['config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_env_config.yaml');
            }]);
        } finally {
            SdkFeature::setHandler(null);
        }
    }


    public function testEnvConfigFileRequiresTomlParser(): void
    {
        SdkFeature::setHandler(static fn (string $kind, string $class): bool => $class != 'Internal\Toml\Toml');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.clients.default.envConfig.configFile" needs a TOML parser: run "composer require internal/toml".');

        try {
            self::bootKernel(['config' => static function (TestKernel $kernel): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_env_config.yaml');
            }]);
        } finally {
            SdkFeature::setHandler(null);
        }
    }


    public function testRegisterServiceClient(): void
    {
        $cloudDef      = null;
        $defaultDef    = null;
        $cloudArgument = null;

        self::bootKernel([
            'config' => static function (TestKernel $kernel) use (&$cloudDef, &$defaultDef, &$cloudArgument): void {
                $kernel->addTestBundle(TemporalBundle::class);
                $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

                $kernel->addTestCompilerPass(new class($cloudDef, $defaultDef, $cloudArgument) implements CompilerPass {
                    public function __construct(
                        public ?Definition &$cloudDef,
                        public ?Definition &$defaultDef,
                        public mixed &$cloudArgument,
                    ) {
                    }

                    public function process(ContainerBuilder $container): void
                    {
                        $this->cloudArgument = $container->getDefinition('temporal.cloud.client')->getArgument('$serviceClient');
                        // Arguments are copied: later passes resolve the named arguments of the same definitions
                        $this->cloudDef   = clone $container->getDefinition('temporal.cloud.service_client');
                        $this->defaultDef = clone $container->getDefinition('temporal.default.service_client');
                    }
                });
            },
        ]);

        assertEquals(new Reference('temporal.cloud.service_client'), $cloudArgument);

        assertInstanceOf(Definition::class, $cloudDef);
        assertEquals([ServiceClient::class, 'createSSL'], $cloudDef->getFactory());
        assertStringContainsString('TEMPORAL_CLIENT_KEY_PATH', (string) $cloudDef->getArgument(2)); // @phpstan-ignore cast.string
        assertStringContainsString('TEMPORAL_CLIENT_CERT_PATH', (string) $cloudDef->getArgument(3)); // @phpstan-ignore cast.string

        assertInstanceOf(Definition::class, $defaultDef);
        assertEquals([ServiceClient::class, 'create'], $defaultDef->getFactory());
        assertStringContainsString('TEMPORAL_ADDRESS', (string) $defaultDef->getArgument(0)); // @phpstan-ignore cast.string
        assertEquals(['withContext'], array_column($defaultDef->getMethodCalls(), 0));
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: ClientOptions}>
     */
    public static function registerClientOptionsDataProvider(): iterable
    {
        yield ['temporal.default.client', ['withNamespace' => 'default', 'withIdentity' => 'default_x', 'withQueryRejectionCondition' => 0]];
        yield ['temporal.foo.client', ['withNamespace' => 'foo', 'withIdentity' => 'foo_x', 'withQueryRejectionCondition' => 1]];
        yield ['temporal.bar.client', ['withNamespace' => 'bar', 'withIdentity' => 'bar_x', 'withQueryRejectionCondition' => 2]];
        yield ['temporal.cloud.client', ['withNamespace' => 'cloud', 'withIdentity' => 'cloud_x', 'withQueryRejectionCondition' => 2]];
    }

    public function testRegisterDefaultClient(): void
    {
        $hasAlias = false;
        $aliasId  = null;

        self::bootKernel(['config' => static function (TestKernel $kernel) use (&$hasAlias, &$aliasId): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');

            $kernel->addTestCompilerPass(new class($hasAlias, $aliasId) implements CompilerPass {
                public function __construct(
                    public bool    &$hasAlias,
                    public ?string &$aliasId,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    $this->hasAlias = $container->hasAlias(WorkflowClient::class);
                    $this->aliasId  = $container->getAlias(WorkflowClient::class)->__toString();
                }
            });
        }]);

        assertTrue($hasAlias);
        assertEquals('temporal.bar.client', $aliasId);
    }
}
