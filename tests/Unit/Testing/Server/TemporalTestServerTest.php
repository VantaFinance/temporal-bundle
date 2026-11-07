<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\Fixture;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ProcessServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestServer;

#[CoversClass(TemporalTestServer::class)]
#[CoversClass(ProcessServer::class)]
final class TemporalTestServerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Fixture::temporaryDirectory();
    }

    protected function tearDown(): void
    {
        Fixture::removeDirectory($this->directory);
    }

    public function testStartAndStopWithTcpReadiness(): void
    {
        $server = new TemporalTestServer(
            binary: Fixture::binary('fake-test-server'),
            port: Fixture::freePort(),
            logFile: $this->directory . '/test-server.log',
            startTimeout: 5.0,
        );

        $server->start();

        assertTrue($server->isRunning());

        $server->stop();

        assertFalse($server->isRunning());
    }

    public function testDownloadSkipsExistingBinary(): void
    {
        $binary = $this->directory . '/temporal-test-server';

        touch($binary);

        assertSame($binary, TemporalTestServer::download($this->directory));
    }
}
