<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use DateInterval;

use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Migration\NamespaceBuilder;

#[CoversClass(NamespaceBuilder::class)]
final class NamespaceBuilderTest extends TestCase
{
    private MigrationRpcContext $rpc;

    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        // Validation must complete without opening a connection to this unreachable endpoint.
        $this->rpc = new MigrationRpcContext(ServiceClient::create('127.0.0.1:1'), OperatorClient::create('127.0.0.1:1'));
    }

    public function testCalendarDependentRetentionRejectedWithoutRpc(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('positive fixed interval');

        (new NamespaceBuilder($this->rpc, 'billing'))->retention(new DateInterval('P1M'));
    }

    public function testEmptyMetadataIsRejectedBeforeRpc(): void
    {
        foreach (['description', 'ownerEmail'] as $method) {
            try {
                (new NamespaceBuilder($this->rpc, 'billing'))->{$method}('   ');

                self::fail('Empty metadata must be rejected.');
            } catch (MigrationException $exception) {
                assertStringContainsString('empty', $exception->getMessage());
            }
        }
    }

    public function testPendingMetadataMustBeEnsured(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('call ensure()');

        (new NamespaceBuilder($this->rpc, 'billing'))->data(['team' => 'billing'])
            ->assertApplied()
        ;
    }

    public function testDeleteRejectsPendingSettingsBeforeRpc(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('pending');

        (new NamespaceBuilder($this->rpc, 'billing'))->retention(new DateInterval('P1D'))
            ->delete()
        ;
    }
}
