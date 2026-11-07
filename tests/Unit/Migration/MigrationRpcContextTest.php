<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;

#[CoversClass(MigrationRpcContext::class)]
final class MigrationRpcContextTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
    }

    public function testMetadataAndPerRpcDeadlineArePreservedOnBothClients(): void
    {
        $context = Context::default()->withMetadata(['fixture' => ['value']])
            ->withTimeout(2)
        ;
        $operator = OperatorClient::create('127.0.0.1:1')->withContext($context);
        $service  = ServiceClient::create('127.0.0.1:1')->withContext($context);
        $rpc      = new MigrationRpcContext($service, $operator, 30);
        $before   = microtime(true);

        foreach ([false, true] as $serviceContext) {
            $capped = $rpc->context($serviceContext);

            assertSame(['fixture' => ['value']], $capped->getMetadata());
            assertNotNull($capped->getDeadline());
            assertLessThan($before + 3, (float) $capped->getDeadline()->format('U.u'));
        }

        foreach ([$rpc->operatorClient(), $rpc->serviceClient()] as $client) {
            $actual = $client->getContext();

            assertSame(['fixture' => ['value']], $actual->getMetadata());
            assertNotNull($actual->getDeadline());
            assertLessThan($before + 3, (float) $actual->getDeadline()->format('U.u'));
        }

        assertSame($context, $operator->getContext());
        assertSame($context, $service->getContext());
    }

    public function testObservationCannotExtendInvocationDeadline(): void
    {
        $time     = 0.0;
        $operator = OperatorClient::create('127.0.0.1:1');
        $service  = ServiceClient::create('127.0.0.1:1');
        $rpc      = new MigrationRpcContext($service, $operator, 1, clock: static function () use (&$time): float {
            return $time;
        }, sleep: static function (float $seconds) use (&$time): void {
            $time += $seconds;
        });
        $reads = 0;

        try {
            $rpc->observe(static function () use (&$reads): bool {
                ++$reads;

                return false;
            }, 'fixture', 60, 0.4);

            self::fail('Expected a bounded deadline failure.');
        } catch (MigrationException $exception) {
            assertStringContainsString('deadline', $exception->getMessage());
            assertSame(3, $reads);
            assertSame(1.0, $time);
        }

        $this->expectException(MigrationException::class);

        $rpc->context();
    }

    public function testObservationRequiresStateConfirmationEvenWithoutRpcError(): void
    {
        $rpc   = new MigrationRpcContext(ServiceClient::create('127.0.0.1:1'), OperatorClient::create('127.0.0.1:1'));
        $reads = 0;

        try {
            $rpc->observe(static function () use (&$reads): bool {
                ++$reads;

                return false;
            }, 'unconfirmed state', 3, 0.0);

            self::fail('An unconfirmed state must exhaust observation attempts.');
        } catch (MigrationException $exception) {
            assertSame(3, $reads);
            assertStringContainsString('Unable to verify', $exception->getMessage());
        }
    }
}
