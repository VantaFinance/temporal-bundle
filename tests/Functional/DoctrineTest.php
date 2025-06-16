<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Temporal\Interceptor\SimplePipelineProvider;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\DoctrineCompilerPass;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\reference;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[CoversClass(DoctrineCompilerPass::class)]
final class DoctrineTest extends KernelTestCase
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


    public function testRegisterDoctrineClearEntityManagerFinalizer(): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return $package == 'doctrine/doctrine-bundle';
        });

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');


            $kernel->addTestCompilerPass(new class() implements CompilerPass {
                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition('temporal.doctrine_clear_entity_manager.finalizer'));
                }
            });
        }]);
    }


    /**
     * @param non-empty-string $id
     * @param array{0: Reference, 1: non-empty-string}  $arguments
     */
    #[DataProvider('registerDoctrinePingFinalizersDataProvider')]
    public function testRegisterDoctrinePingFinalizers(string $id, array $arguments): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return $package == 'doctrine/doctrine-bundle';
        });


        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id, $arguments): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');

            $kernel->addTestCompilerPass(new class() implements CompilerPass {
                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition('temporal.doctrine_clear_entity_manager.finalizer'));
                }
            });

            $kernel->addTestCompilerPass(new class($id, $arguments) implements CompilerPass {
                /**
                 * @param non-empty-string $id
                 * @param array{0: Reference, 1: non-empty-string}  $arguments
                 */
                public function __construct(
                    private readonly string $id,
                    private readonly array $arguments,
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));
                    assertArrayHasKey(0, $container->getDefinition($this->id)->getArguments());
                    assertInstanceOf(Definition::class, $container->getDefinition($this->id)->getArguments()[0]);
                    assertEquals($this->arguments, $container->getDefinition($this->id)->getArguments()[0]->getArguments());
                }
            });
        }]);
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: array{0: Reference, 1: non-empty-string}}>
     */
    public static function registerDoctrinePingFinalizersDataProvider(): iterable
    {
        yield ['temporal.doctrine_ping_connection_default.finalizer', [new Reference('doctrine'), 'default', referenceLogger()]];
        yield ['temporal.doctrine_ping_connection_customer.finalizer', [new Reference('doctrine'), 'customer', referenceLogger()]];
    }


    /**
     * @param non-empty-string                $id
     */
    #[DataProvider('doctrineInspectorActivity')]
    public function testRegisterDoctrineInspectorActivity(string $id): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return $package == 'doctrine/doctrine-bundle';
        });


        self::bootKernel(['config' => static function (TestKernel $kernel) use ($id): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_with_finalizers.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');


            $kernel->addTestCompilerPass(new class($id) implements CompilerPass {
                /**
                 * @param non-empty-string                $id
                 */
                public function __construct(
                    private readonly string $id,
                ) {
                }


                public function process(ContainerBuilder $container): void
                {
                    assertTrue($container->hasDefinition($this->id));
                }
            });
        }]);
    }



    /**
     * @return iterable<array<non-empty-string>>
     */
    public static function doctrineInspectorActivity(): iterable
    {
        yield [
            'temporal.doctrine_ping_connection_default_activity_inbound.interceptor',
        ];

        yield [
            'temporal.doctrine_ping_connection_customer_activity_inbound.interceptor',
        ];
    }


    /**
     * @param non-empty-string $entityManager
     * @param non-empty-string $workerId
     */
    #[DataProvider('doctrineEntityManagers')]
    public function testRegisterDoctrineIntegrationForSpecificWorker(string $entityManager, string $workerId): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['doctrine/doctrine-bundle', 'vanta/temporal-doctrine']);
        });


        self::bootKernel(['config' => static function (TestKernel $kernel) use ($entityManager, $workerId): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_use_specific_doctrine_worker.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');


            $kernel->addTestCompilerPass(new class($entityManager, $workerId) implements CompilerPass {
                /**
                 * @param non-empty-string $entityManager
                 */
                public function __construct(
                    private readonly string $entityManager,
                    private readonly string $workerId,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    /** @var Definition|mixed $doctrineUseWorkerPipeline */
                    $doctrineUseWorkerPipeline = $container->getDefinition($this->workerId)->getArgument(3);
                    $doctrineChainFinalizer    = definition(ChainFinalizer::class, [
                        [
                            reference('temporal.framework.finalizer'),
                            reference(sprintf('temporal.doctrine_ping_connection_%s.finalizer', $this->entityManager)),
                            reference('temporal.doctrine_clear_entity_manager.finalizer'),
                        ],
                        referenceLogger(),
                    ]);

                    assertInstanceOf(Definition::class, $doctrineUseWorkerPipeline);
                    assertEquals(SimplePipelineProvider::class, $doctrineUseWorkerPipeline->getClass());
                    assertEquals([
                        [new Reference(sprintf('temporal.doctrine_ping_connection_%s_activity_inbound.interceptor', $this->entityManager))],
                    ], $doctrineUseWorkerPipeline->getArguments());
                    assertEquals([
                        ['registerActivityFinalizer', [
                            definition(Closure::class, [[$doctrineChainFinalizer, 'finalize']])
                                ->setFactory([Closure::class, 'fromCallable']),
                        ]],
                    ], $container->getDefinition($this->workerId)->getMethodCalls());


                    /** @var Definition|mixed $withoutDoctrineWorkerPipeline */
                    $withoutDoctrineWorkerPipeline = $container->getDefinition('temporal.without_doctrine.worker')->getArgument(3);
                    $withoutDoctrineChainFinalizer = definition(ChainFinalizer::class, [
                        [reference('temporal.framework.finalizer')],
                        referenceLogger(),
                    ]);


                    assertInstanceOf(Definition::class, $withoutDoctrineWorkerPipeline);
                    assertEquals(SimplePipelineProvider::class, $withoutDoctrineWorkerPipeline->getClass());
                    assertEquals([[]], $withoutDoctrineWorkerPipeline->getArguments());
                    assertEquals([
                        ['registerActivityFinalizer', [
                            definition(Closure::class, [[$withoutDoctrineChainFinalizer, 'finalize']])
                                ->setFactory([Closure::class, 'fromCallable']),
                        ]],
                    ], $container->getDefinition('temporal.without_doctrine.worker')->getMethodCalls());
                }
            });
        }]);
    }


    /**
     * @return iterable<array<non-empty-string>>
     */
    public static function doctrineEntityManagers(): iterable
    {
        yield ['default', 'temporal.doctrine_with_default_use.worker'];
        yield ['customer', 'temporal.doctrine_with_customer_use.worker'];
    }




    #[DataProvider('workersWithDoctrine')]
    public function testRegisterDoctrineIntegrationForAllWorker(string $workerId): void
    {
        InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
            return in_array($package, ['doctrine/doctrine-bundle', 'vanta/temporal-doctrine']);
        });


        self::bootKernel(['config' => static function (TestKernel $kernel) use ($workerId): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal_use_global_doctrine.yaml');
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');


            $kernel->addTestCompilerPass(new class($workerId) implements CompilerPass {
                public function __construct(
                    private readonly string $workerId,
                ) {
                }

                public function process(ContainerBuilder $container): void
                {
                    /** @var Definition|mixed $doctrineUseWorkerPipeline */
                    $doctrineUseWorkerPipeline = $container->getDefinition($this->workerId)->getArgument(3);
                    $doctrineChainFinalizer    = definition(ChainFinalizer::class, [
                        [
                            reference('temporal.framework.finalizer'),
                            reference('temporal.doctrine_ping_connection_default.finalizer'),
                            reference('temporal.doctrine_clear_entity_manager.finalizer'),
                        ],
                        referenceLogger(),
                    ]);

                    assertInstanceOf(Definition::class, $doctrineUseWorkerPipeline);
                    assertEquals(SimplePipelineProvider::class, $doctrineUseWorkerPipeline->getClass());
                    assertEquals([
                        [new Reference(sprintf('temporal.doctrine_ping_connection_default_activity_inbound.interceptor'))],
                    ], $doctrineUseWorkerPipeline->getArguments());
                    assertEquals([
                        ['registerActivityFinalizer', [
                            definition(Closure::class, [[$doctrineChainFinalizer, 'finalize']])
                                ->setFactory([Closure::class, 'fromCallable']),
                        ]],
                    ], $container->getDefinition($this->workerId)->getMethodCalls());
                }
            });
        }]);
    }


    /**
     * @return iterable<array<non-empty-string>>
     */
    public static function workersWithDoctrine(): iterable
    {
        yield ['temporal.doctrine_use.worker'];
        yield ['temporal.without_doctrine.worker'];
    }



    #[DataProvider('temporalInvalidConfigurationDoctrine')]
    public function testInvalidConfigDoctrineIntegration(string $pathTemporalConfig, InvalidConfigurationException $e, ?callable $configurator = null): void
    {
        $this->expectExceptionObject($e);

        $configurator ??= static function (): void {
            InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
                return in_array($package, ['doctrine/doctrine-bundle', 'vanta/temporal-doctrine']);
            });
        };

        $configurator();

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($pathTemporalConfig): void {
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . $pathTemporalConfig);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/doctrine.yaml');
        }]);
    }


    /**
     * @return iterable<array{0: non-empty-string, 1: InvalidConfigurationException, 2?: callable(): void}>
     */
    public static function temporalInvalidConfigurationDoctrine(): iterable
    {
        yield ['/Framework/Config/temporal_use_global_doctrine_invalid_config.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.pool.useGlobalDoctrineIntegration": Please set entity-manager name.')];
        yield ['/Framework/Config/temporal_use_global_doctrine_not_found_entity_manager.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.pool.useGlobalDoctrineIntegration": Not found entity managers: spiral, test')];
        yield ['/Framework/Config/temporal_use_global_doctrine_repeated_entity_manager.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.pool.useGlobalDoctrineIntegration": Should not be repeated entity-manager')];
        yield ['/Framework/Config/temporal_use_global_doctrine.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.pool.useGlobalDoctrineIntegration": Install dependencies `composer req orm`'), static function (): void {
            InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
                return false;
            });
        }];
        yield ['/Framework/Config/temporal_use_global_doctrine.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.pool.useGlobalDoctrineIntegration": Install dependencies `composer req temporal-doctrine`'), static function (): void {
            InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
                return $package == 'doctrine/doctrine-bundle';
            });
        }];
        yield ['/Framework/Config/temporal_use_specific_doctrine_worker.yaml', invalidConfiguration('temporal.pool.useGlobalDoctrineIntegration', 'Invalid configuration for path "temporal.workers.doctrine_with_default_use.useDoctrineIntegration": Install dependencies `composer req orm`'), static function (): void {
            InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
                return false;
            });
        }];
        yield ['/Framework/Config/temporal_use_specific_doctrine_worker.yaml', invalidConfiguration('temporal.pool.gavno', 'Invalid configuration for path "temporal.workers.doctrine_with_default_use.useDoctrineIntegration": Install dependencies `composer req temporal-doctrine`'), static function (): void {
            InstalledVersions::setHandler(static function (string $package, string $class, array $parentPackages): bool {
                return $package == 'doctrine/doctrine-bundle';
            });
        }];
    }
}


/**
 * @param non-empty-string $path
 * @param non-empty-string $message
 */
function invalidConfiguration(string $path, string $message): InvalidConfigurationException
{
    $e = new InvalidConfigurationException($message);
    $e->setPath($path);

    return $e;
}
