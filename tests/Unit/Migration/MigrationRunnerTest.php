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
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDescriptor;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDiscovery;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationPaths;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;

#[CoversClass(MigrationRunner::class)]
final class MigrationRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
    }

    /** @var list<string> */
    public static array $calls = [];
    public static bool $fail   = false;

    public function testAlwaysRunsFullCatalogAndDownReversesOrder(): void
    {
        self::$calls = [];
        self::$fail  = false;
        $runner      = $this->runner();
        $catalog     = $this->catalog();
        $runner->run('up', $catalog);
        $runner->run('up', $catalog);
        $runner->run('down', $catalog);
        $runner->run('up', $catalog);
        self::assertSame(['first-up','second-up','first-up','second-up','second-down','first-down','first-up','second-up'], self::$calls);
    }

    public function testFailureRetainsCauseAndNextInvocationStartsFromBeginning(): void
    {
        self::$calls = [];
        self::$fail  = true;
        $runner      = $this->runner();

        try {
            $runner->run('up', $this->catalog());
            self::fail('Expected failure.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('after 1 completed', $exception->getMessage());
            self::assertNotNull($exception->getPrevious());
        }
        self::$fail = false;
        $runner->run('up', $this->catalog());
        self::assertSame(['first-up','second-up','first-up','second-up'], self::$calls);
    }

    /** @return list<MigrationDescriptor> */
    private function catalog(): array
    {
        return [new MigrationDescriptor('1', __FILE__, FirstRunnerMigration::class), new MigrationDescriptor('2', __FILE__, SecondRunnerMigration::class)];
    }

    private function runner(): MigrationRunner
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $service  = $this->createMock(ServiceClientInterface::class);

        foreach ([$operator, $service] as $client) {
            $client->method('getContext')->willReturn(Context::default());
            $client->method('withContext')->willReturnSelf();
        }
        $operator->expects(self::never())->method('ListSearchAttributes');
        $service->expects(self::never())->method('DescribeNamespace');

        return new MigrationRunner(new MigrationDiscovery(new MigrationPaths(['Unused' => sys_get_temp_dir()], '/')), $service, $operator, 'default');
    }
}
