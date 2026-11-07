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

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClient;
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

        assertTrue($contract->isInterface());

        foreach (['up', 'down'] as $direction) {
            $parameters = $contract->getMethod($direction)->getParameters();

            assertCount(1, $parameters);
            assertSame(Scheme::class, (string) $parameters[0]->getType());
        }
    }

    public function testPendingSettingsRequireApplicationAndExposeClients(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        $operator = OperatorClient::create('127.0.0.1:1');
        $service  = ServiceClient::create('127.0.0.1:1');
        $scheme   = new Scheme(new MigrationRpcContext($service, $operator), 'default');

        $scheme->searchAttributes('first')
            ->remove('Absent')
        ;

        $scheme->searchAttributes('second')
            ->remove('Absent')
        ;

        assertInstanceOf(OperatorClient::class, $scheme->operatorClient());
        assertInstanceOf(ServiceClient::class, $scheme->serviceClient());

        $scheme->namespace()
            ->retention(new DateInterval('P1D'))
        ;

        $this->expectException(MigrationException::class);

        $scheme->assertApplied();
    }
}
