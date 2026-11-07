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
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
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
        $context   = Context::default()->withMetadata(['fixture' => ['value']])->withTimeout(2);
        $operator  = $this->createMock(OperatorClientInterface::class);
        $service   = $this->createMock(ServiceClientInterface::class);
        $deadlines = [];

        foreach ([$operator, $service] as $client) {
            $client->method('getContext')->willReturn($context);
            $client->expects(self::once())->method('withContext')->with(self::callback(static function (ContextInterface $context) use (&$deadlines): bool {
                self::assertSame(['fixture' => ['value']], $context->getMetadata());
                self::assertNotNull($context->getDeadline());
                $deadlines[] = (float) $context->getDeadline()->format('U.u');

                return true;
            }))->willReturnSelf();
        }
        $rpc    = new MigrationRpcContext($service, $operator, 30);
        $before = microtime(true);

        foreach ([false, true] as $serviceContext) {
            $capped = $rpc->context($serviceContext);
            self::assertSame(['fixture' => ['value']], $capped->getMetadata());
            self::assertNotNull($capped->getDeadline());
            self::assertLessThan($before + 3, (float) $capped->getDeadline()->format('U.u'));
        }
        self::assertSame($operator, $rpc->operatorClient());
        self::assertSame($service, $rpc->serviceClient());
        self::assertLessThan($before + 3, $deadlines[0]);
        self::assertLessThan($before + 3, $deadlines[1]);
    }

    public function testObservationCannotExtendInvocationDeadline(): void
    {
        $time     = 0.0;
        $operator = $this->createMock(OperatorClientInterface::class);
        $service  = $this->createMock(ServiceClientInterface::class);

        foreach ([$operator, $service] as $client) {
            $client->method('getContext')->willReturn(Context::default());
            $client->method('withContext')->willReturnSelf();
        }
        $rpc = new MigrationRpcContext($service, $operator, 1, clock: static function () use (&$time): float {
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
            self::assertStringContainsString('deadline', $exception->getMessage());
            self::assertSame(3, $reads);
            self::assertSame(1.0, $time);
        }
        $this->expectException(MigrationException::class);
        $rpc->context();
    }
}
