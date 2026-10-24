<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use ArrayObject;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\CollectingLogger;
use Vanta\Integration\Symfony\Temporal\Testing\Server\Server;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ServerStartFailed;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

#[CoversClass(TemporalTestEnvironment::class)]
final class TemporalTestEnvironmentTest extends TestCase
{
    /**
     * @var ArrayObject<int, string>
     */
    private ArrayObject $journal;

    protected function setUp(): void
    {
        $this->journal = new ArrayObject();
    }

    public function testStartsInOrderAndStopsInReverseOrder(): void
    {
        $environment = new TemporalTestEnvironment($this->server('a'), $this->server('b'), $this->server('c'));

        $environment->start();

        assertTrue($environment->isRunning());

        $environment->stop();

        assertFalse($environment->isRunning());
        assertSame(['start a', 'start b', 'start c', 'stop c', 'stop b', 'stop a'], $this->journal->getArrayCopy());
    }

    public function testRollsBackStartedServersWhenOneFails(): void
    {
        $failure     = new ServerStartFailed('b failed');
        $logger      = new CollectingLogger();
        $environment = (new TemporalTestEnvironment(
            $this->server('a'),
            $this->server('b', startFailure: $failure),
            $this->server('c'),
        ))->withLogger($logger);

        try {
            $environment->start();
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed $e) {
            assertSame($failure, $e);
        }

        assertSame(['start a', 'start b', 'stop a'], $this->journal->getArrayCopy());
        assertTrue($logger->hasLevel('error'));
    }

    public function testStopContinuesAfterFailureAndRethrowsFirst(): void
    {
        $first       = new RuntimeException('c failed');
        $environment = new TemporalTestEnvironment(
            $this->server('a', stopFailure: new RuntimeException('a failed')),
            $this->server('b'),
            $this->server('c', stopFailure: $first),
        );

        $environment->start();

        try {
            $environment->stop();
            self::fail('RuntimeException expected');
        } catch (RuntimeException $e) {
            assertSame($first, $e);
        }

        assertSame(['start a', 'start b', 'start c', 'stop c', 'stop b', 'stop a'], $this->journal->getArrayCopy());
    }

    public function testIsRunningRequiresAllServers(): void
    {
        $stopped     = $this->server('b');
        $environment = new TemporalTestEnvironment($this->server('a'), $stopped);

        $environment->start();
        $stopped->stop();

        assertFalse($environment->isRunning());
        assertFalse((new TemporalTestEnvironment())->isRunning());
    }

    public function testShutdownHookIsRegisteredOncePerProcess(): void
    {
        $environment = new TemporalTestEnvironment($this->server('a'));

        $environment->start();
        $environment->stop();

        $logger     = new CollectingLogger();
        $withLogger = (new TemporalTestEnvironment($this->server('b')))->withLogger($logger);

        $withLogger->start();
        $withLogger->stop();
        $withLogger->start();
        $withLogger->stop();

        assertSame(0, $logger->count('debug', 'Registered shutdown hook for test environment'));
    }

    private function server(string $name, ?ServerStartFailed $startFailure = null, ?RuntimeException $stopFailure = null): Server
    {
        return new class($name, $this->journal, $startFailure, $stopFailure) implements Server {
            private bool $running = false;

            /**
             * @param ArrayObject<int, string> $journal
             */
            public function __construct(
                private readonly string $name,
                private readonly ArrayObject $journal,
                private readonly ?ServerStartFailed $startFailure,
                private readonly ?RuntimeException $stopFailure,
            ) {
            }

            public function start(): void
            {
                $this->journal->append('start ' . $this->name);

                if ($this->startFailure != null) {
                    throw $this->startFailure;
                }

                $this->running = true;
            }

            public function stop(): void
            {
                if (!$this->running) {
                    return;
                }

                $this->journal->append('stop ' . $this->name);
                $this->running = false;

                if ($this->stopFailure != null) {
                    throw $this->stopFailure;
                }
            }

            public function isRunning(): bool
            {
                return $this->running;
            }
        };
    }
}
