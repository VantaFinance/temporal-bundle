<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2024, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsArray;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Client\ScheduleClientInterface as ScheduleClient;
use Vanta\Integration\Symfony\Temporal\Client\GrpcServiceClientFactory;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ScheduleClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

/**
 * @phpstan-type ClientOptions array{
 *   withNamespace: non-empty-string,
 *   withIdentity: non-empty-string,
 *   withQueryRejectionCondition: int
 * }
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ScheduleClientCompilerPass::class)]
final class ScheduleClientTest extends KernelTestCase
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
                    $this->hasDefault = $container->has('temporal.default.schedule_client');
                    $this->hasFoo     = $container->has('temporal.foo.schedule_client');
                    $this->hasBar     = $container->has('temporal.bar.schedule_client');
                    $this->hasCloud   = $container->has('temporal.cloud.schedule_client');
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
                    $this->hasDefault = $container->hasAlias('Temporal\Client\ScheduleClientInterface $defaultScheduleClient');
                    $this->hasFoo     = $container->hasAlias('Temporal\Client\ScheduleClientInterface $fooScheduleClient');
                    $this->hasBar     = $container->hasAlias('Temporal\Client\ScheduleClientInterface $barScheduleClient');
                    $this->hasCloud   = $container->hasAlias('Temporal\Client\ScheduleClientInterface $cloudScheduleClient');
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
                        $this->cloudArgument = $container->getDefinition('temporal.cloud.schedule_client')->getArgument('$serviceClient');
                        // Arguments are copied: later passes resolve the named arguments of the same definitions
                        $this->cloudDef   = clone $container->getDefinition('temporal.cloud.schedule_service_client');
                        $this->defaultDef = clone $container->getDefinition('temporal.default.schedule_service_client');
                    }
                });
            },
        ]);

        assertEquals(new Reference('temporal.cloud.schedule_service_client'), $cloudArgument);

        assertInstanceOf(Definition::class, $cloudDef);
        assertEquals([GrpcServiceClientFactory::class, 'create'], $cloudDef->getFactory());
        $tls = $cloudDef->getArgument('$tls');

        assertIsArray($tls);
        assertStringContainsString('TEMPORAL_CLIENT_KEY_PATH', (string) $tls['clientKey']); // @phpstan-ignore cast.string
        assertStringContainsString('TEMPORAL_CLIENT_CERT_PATH', (string) $tls['clientPem']); // @phpstan-ignore cast.string

        assertInstanceOf(Definition::class, $defaultDef);
        assertEquals([GrpcServiceClientFactory::class, 'create'], $defaultDef->getFactory());
        assertStringContainsString('TEMPORAL_ADDRESS', (string) $defaultDef->getArgument('$address')); // @phpstan-ignore cast.string
        assertEquals(['enabled' => null, 'rootCa' => null, 'clientKey' => null, 'clientPem' => null, 'serverName' => null], $defaultDef->getArgument('$tls'));
        assertInstanceOf(Definition::class, $defaultDef->getArgument('$context'));
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: ClientOptions}>
     */
    public static function registerClientOptionsDataProvider(): iterable
    {
        yield ['temporal.default.schedule_client', ['withNamespace' => 'default', 'withIdentity' => 'default_x', 'withQueryRejectionCondition' => 0]];
        yield ['temporal.foo.schedule_client', ['withNamespace' => 'foo', 'withIdentity' => 'foo_x', 'withQueryRejectionCondition' => 1]];
        yield ['temporal.bar.schedule_client', ['withNamespace' => 'bar', 'withIdentity' => 'bar_x', 'withQueryRejectionCondition' => 2]];
        yield ['temporal.cloud.schedule_client', ['withNamespace' => 'cloud', 'withIdentity' => 'cloud_x', 'withQueryRejectionCondition' => 2]];
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
                    $this->hasAlias = $container->hasAlias(ScheduleClient::class);
                    $this->aliasId  = $container->getAlias(ScheduleClient::class)->__toString();
                }
            });
        }]);

        assertTrue($hasAlias);
        assertEquals('temporal.bar.schedule_client', $aliasId);
    }
}
