<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e\Migration;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClient;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalDevServer;

abstract class MigrationServerTestCase extends TestCase
{
    private static ?TemporalDevServer $server = null;
    protected static ServiceClient $service;
    protected static OperatorClientInterface $operator;

    final public static function setUpBeforeClass(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Unable to allocate a Temporal test port.');
        }

        $address = stream_socket_get_name($socket, false);

        fclose($socket);

        if ($address === false || $address === '') {
            throw new RuntimeException('Unable to determine the Temporal test address.');
        }

        $binary       = getenv('TEMPORAL_MIGRATION_BINARY') ?: dirname(__DIR__, 3) . '/temporal';
        self::$server = new TemporalDevServer(binary: $binary, address: $address);

        try {
            self::$server->start();

            self::$service  = ServiceClient::create($address);
            self::$operator = OperatorClient::create($address);
        } catch (Throwable $exception) {
            self::$server->stop();

            self::$server = null;

            throw $exception;
        }
    }

    final public static function tearDownAfterClass(): void
    {
        self::$server?->stop();

        self::$server = null;
    }

    protected function rpc(): MigrationRpcContext
    {
        return new MigrationRpcContext(self::$service, self::$operator, 30);
    }

    protected function namespaceName(): string
    {
        return 'migration-' . bin2hex(random_bytes(5));
    }
}
