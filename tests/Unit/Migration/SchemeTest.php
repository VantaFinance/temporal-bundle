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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesResponse;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\Migration;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Migration\Scheme;

#[CoversClass(Scheme::class)]
final class SchemeTest extends TestCase
{
    public function testMigrationRequiresOnlySchemeAndNoInheritance(): void
    {
        $contract = new ReflectionClass(Migration::class);
        self::assertTrue($contract->isInterface());

        foreach (['up', 'down'] as $direction) {
            $parameters = $contract->getMethod($direction)->getParameters();
            self::assertCount(1, $parameters);
            self::assertSame(Scheme::class, (string) $parameters[0]->getType());
        }
    }

    public function testChildrenKeepIndependentNamespacesAndPendingSettings(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
        $operator = $this->createMock(OperatorClientInterface::class);
        $service  = $this->createMock(ServiceClientInterface::class);

        foreach ([$operator, $service] as $client) {
            $client->method('getContext')->willReturn(Context::default());
            $client->method('withContext')->willReturnSelf();
        }
        $namespaces = [];
        $operator->method('ListSearchAttributes')->willReturnCallback(static function (ListSearchAttributesRequest $request) use (&$namespaces): ListSearchAttributesResponse {
            $namespaces[] = $request->getNamespace();

            return new ListSearchAttributesResponse();
        });
        $scheme = new Scheme(new MigrationRpcContext($service, $operator), 'default');
        $first  = $scheme->searchAttributes('first')->remove('Absent');
        $second = $scheme->searchAttributes('second')->remove('Absent');
        $first->apply();
        $second->apply();
        self::assertSame(['first', 'first', 'second', 'second'], $namespaces);
        self::assertSame($operator, $scheme->operatorClient());
        self::assertSame($service, $scheme->serviceClient());
        $scheme->namespace()->retention(new DateInterval('P1D'));
        $this->expectException(MigrationException::class);
        $scheme->assertApplied();
    }
}
