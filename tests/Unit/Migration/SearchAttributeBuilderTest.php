<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Migration\SearchAttributeBuilder;

#[CoversClass(SearchAttributeBuilder::class)]
final class SearchAttributeBuilderTest extends TestCase
{
    private SearchAttributeBuilder $builder;

    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        $rpc           = new MigrationRpcContext(ServiceClient::create('127.0.0.1:1'), OperatorClient::create('127.0.0.1:1'));
        $this->builder = new SearchAttributeBuilder($rpc, 'billing');
    }

    public function testUnappliedOperationsFailWithoutRpc(): void
    {
        $this->builder->keyword('Queued');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('unapplied operations');

        $this->builder->assertApplied();
    }

    public function testInvalidNamesAreRejectedWithoutRpc(): void
    {
        foreach (['', ' ', "Invalid\nName", 'TemporalReserved'] as $name) {
            try {
                $this->builder->keyword($name);

                self::fail('Expected invalid attribute name to fail.');
            } catch (MigrationException $exception) {
                assertStringContainsString('name', $exception->getMessage());
            }
        }
    }

    public function testContradictoryOperationsAreRejectedWithoutRpc(): void
    {
        $this->builder->keyword('CustomerId');

        $this->expectException(MigrationException::class);

        $this->builder->remove('CustomerId');
    }

    public function testEmptyBatchDoesNotNeedServer(): void
    {
        $this->builder->apply();

        $this->builder->assertApplied();

        $this->expectNotToPerformAssertions();
    }
}
