<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\MigrationCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationGenerator;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;

#[CoversClass(MigrationCompilerPass::class)]
final class MigrationCompilerPassTest extends TestCase
{
    protected function tearDown(): void
    {
        SdkFeature::setHandler(null);
    }

    public function testDisabledOnOldSdkDoesNotRegisterAnything(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);
        $container = $this->container(false);
        (new MigrationCompilerPass())->process($container);
        self::assertFalse($container->has(MigrationGenerator::class));
    }

    public function testSelectedNamedClientsAndOptionalLogger(): void
    {
        if (!interface_exists('Temporal\\Client\\GRPC\\OperatorClientInterface')) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
        $container = $this->container();
        (new MigrationCompilerPass())->process($container);
        $arguments = $container->getDefinition(MigrationRunner::class)->getArguments();
        self::assertSame('temporal.other.service_client', (string) $arguments[1]);
        self::assertSame('temporal.other.operator_client', (string) $arguments[2]);
        self::assertSame('billing', $arguments[3]);
        self::assertTrue($container->has('temporal.migrations.null_logger'));
    }

    public function testOldSdkGivesActionableError(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);
        $this->expectExceptionMessage('requires temporal/sdk >= 2.18');
        (new MigrationCompilerPass())->process($this->container());
    }

    public function testUnknownClientFailsBeforeRuntime(): void
    {
        if (!interface_exists('Temporal\\Client\\GRPC\\OperatorClientInterface')) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
        $container = $this->container();
        $config    = $container->getParameter('temporal.config');
        self::assertIsArray($config);
        $config['migrations']['client'] = 'missing';
        $container->setParameter('temporal.config', $config);
        $this->expectException(InvalidArgumentException::class);
        (new MigrationCompilerPass())->process($container);
    }

    public function testInvalidCustomLoggerAndEnvConfigAreRejected(): void
    {
        if (!interface_exists('Temporal\\Client\\GRPC\\OperatorClientInterface')) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        foreach (['logger', 'envConfig'] as $invalidOption) {
            $container = $this->container();
            $config    = $container->getParameter('temporal.config');
            self::assertIsArray($config);

            if ($invalidOption === 'logger') {
                $config['pool']['globalLogger'] = 'missing.custom.logger';
            } else {
                $config['clients']['other']['envConfig']['enabled'] = true;
            }
            $container->setParameter('temporal.config', $config);

            try {
                (new MigrationCompilerPass())->process($container);
                self::fail('Expected invalid migration configuration.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($invalidOption === 'logger' ? 'logger service' : 'envConfig', $error->getMessage());
            }
        }
    }

    private function container(bool $enabled = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('temporal.config', (new Processor())->processConfiguration(new Configuration([], []), [[
            'clients'    => ['other' => ['namespace' => 'billing']],
            'migrations' => ['enabled' => $enabled, 'client' => 'other', 'paths' => ['AppMigrations' => 'not-created']],
        ]]));

        return $container;
    }
}
