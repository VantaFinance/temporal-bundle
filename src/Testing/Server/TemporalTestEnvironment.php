<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Throwable;

/**
 * Starts servers in the given order and stops them in reverse order.
 *
 * start() registers a shutdown hook, so the servers are stopped gracefully even without an explicit stop().
 */
final class TemporalTestEnvironment implements Server
{
    /**
     * @var list<Server>
     */
    private readonly array $servers;

    /**
     * Environments started in this process: one shutdown function stops them all.
     *
     * @var SplObjectStorage<self, null>|null
     */
    private static ?SplObjectStorage $started      = null;
    private static int|false|null $shutdownHookPid = null;

    private Logger $logger;

    public function __construct(Server ...$servers)
    {
        $this->servers = array_values($servers);
        $this->logger  = new NullLogger();
    }

    public function withLogger(Logger $logger): self
    {
        $environment         = clone $this;
        $environment->logger = $logger;

        return $environment;
    }

    /**
     * @throws Throwable the original failure, after the already started servers were stopped
     */
    public function start(): void
    {
        // Registered before the servers start, so it runs before their own hooks and keeps the reverse order
        $this->registerShutdownHook();

        $this->logger->info(sprintf('Starting test environment (%d servers)', count($this->servers)));

        $started = [];

        foreach ($this->servers as $index => $server) {
            try {
                $server->start();
            } catch (Throwable $e) {
                $this->logger->error(sprintf('Server #%d (%s) failed to start, rolling back', $index, $server::class), [
                    'exception' => $e,
                ]);

                foreach (array_reverse($started) as $startedServer) {
                    try {
                        $startedServer->stop();
                    } catch (Throwable $stopError) {
                        $this->logger->error(sprintf('Failed to stop %s during rollback', $startedServer::class), [
                            'exception' => $stopError,
                        ]);
                    }
                }

                throw $e;
            }

            $started[] = $server;
        }

        $this->logger->info('Test environment started');
    }

    /**
     * @throws Throwable the first failure, after every server was asked to stop
     */
    public function stop(): void
    {
        self::$started?->detach($this);

        if (!$this->isAnyServerRunning()) {
            // e.g. the shutdown hook of an environment that a test already stopped
            $this->logger->debug('Test environment is not running, nothing to stop');

            return;
        }

        $firstError = null;
        $servers    = array_reverse($this->servers);

        // Signal everything first: the servers shut down concurrently instead of one after another
        foreach ($servers as $server) {
            if (!$server instanceof StopsInTwoPhases) {
                continue;
            }

            try {
                $server->signalStop();
            } catch (Throwable $e) {
                $this->logger->error(sprintf('Failed to signal %s', $server::class), ['exception' => $e]);

                $firstError ??= $e;
            }
        }

        foreach ($servers as $server) {
            try {
                $server instanceof StopsInTwoPhases ? $server->waitStopped() : $server->stop();
            } catch (Throwable $e) {
                $this->logger->error(sprintf('Failed to stop %s', $server::class), ['exception' => $e]);

                $firstError ??= $e;
            }
        }

        if ($firstError != null) {
            throw $firstError;
        }

        $this->logger->info('Test environment stopped');
    }

    /**
     * True when the environment has servers and all of them are running.
     */
    public function isRunning(): bool
    {
        if ($this->servers == []) {
            return false;
        }

        foreach ($this->servers as $server) {
            if (!$server->isRunning()) {
                return false;
            }
        }

        return true;
    }

    private function isAnyServerRunning(): bool
    {
        foreach ($this->servers as $server) {
            if ($server->isRunning()) {
                return true;
            }
        }

        return false;
    }

    private function registerShutdownHook(): void
    {
        $pid = getmypid();

        // A forked child inherits the parent's state: it gets its own list and its own shutdown function
        if (self::$started == null || self::$shutdownHookPid !== $pid) {
            self::$started         = new SplObjectStorage();
            self::$shutdownHookPid = $pid;

            register_shutdown_function(static function () use ($pid): void {
                if (getmypid() !== $pid || self::$started == null) {
                    return;
                }

                foreach (array_reverse(iterator_to_array(self::$started, false)) as $environment) {
                    $environment->stopOnShutdown();
                }
            });

            $this->logger->debug('Registered shutdown hook for test environment');
        }

        self::$started->attach($this);
    }

    private function stopOnShutdown(): void
    {
        try {
            $this->stop();
        } catch (Throwable $e) {
            $this->logger->error('Failed to stop test environment on shutdown', ['exception' => $e]);
        }
    }
}
