<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use InvalidArgumentException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Common\SearchAttributes\ValueType;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\Fixture;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ProcessServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalDevServer;

#[CoversClass(TemporalDevServer::class)]
#[CoversClass(ProcessServer::class)]
final class TemporalDevServerTest extends TestCase
{
    private string $directory;
    private int $port;

    protected function setUp(): void
    {
        $this->directory = Fixture::temporaryDirectory();
        $this->port      = Fixture::freePort();
    }

    protected function tearDown(): void
    {
        Fixture::removeDirectory($this->directory);

        @unlink(sprintf('%s/fake-temporal-%d.ready', sys_get_temp_dir(), $this->port));
    }

    public function testStartAndStop(): void
    {
        $server = new TemporalDevServer(
            binary: Fixture::binary('fake-temporal'),
            address: '127.0.0.1:' . $this->port,
            searchAttributes: ['CustomerId' => ValueType::Keyword, 'Amount' => ValueType::Float],
            logFile: $this->directory . '/temporal.log',
            startTimeout: 5.0,
        );

        $server->start();

        assertTrue($server->isRunning());

        $server->stop();

        assertFalse($server->isRunning());
    }

    public function testInvalidAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemporalDevServer(binary: Fixture::binary('fake-temporal'), address: 'localhost');
    }

    public function testEmptyDynamicConfigKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemporalDevServer(binary: Fixture::binary('fake-temporal'), dynamicConfig: ['' => true]);
    }

    public function testInvalidSearchAttributeName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TemporalDevServer(binary: Fixture::binary('fake-temporal'), searchAttributes: ['bad name' => ValueType::Text]);
    }
}
