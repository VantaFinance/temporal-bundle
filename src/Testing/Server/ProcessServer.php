<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use Closure;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Owns one long-running process of a test server.
 *
 * The process output is redirected to a log file instead of pipes: Symfony Process reads pipes
 * only on demand, so a chatty server would fill the OS pipe buffer and block on write.
 *
 * @internal
 */
final class ProcessServer
{
    private const LOG_TAIL_BYTES = 4096;
    private const SIGTERM        = 15;

    /**
     * Started servers of this process; one shutdown function stops them all.
     *
     * @var SplObjectStorage<self, null>|null
     */
    private static ?SplObjectStorage $started      = null;
    private static int|false|null $shutdownHookPid = null;

    private ?Process $process    = null;
    private int $logOffset       = 0;
    private ?float $stopDeadline = null;

    /**
     * @param non-empty-string     $name
     * @param array<string,string> $env
     * @param list<string>         $binaryDirectories   searched after PATH when the binary is a bare name
     * @param bool                 $removeLogFileOnStop for the default temp log: delete it after a successful stop
     */
    public function __construct(
        private readonly string $name,
        private readonly array $env,
        private readonly string $logFile,
        private readonly float $startTimeout,
        private readonly float $stopTimeout,
        private readonly Logger $logger = new NullLogger(),
        private readonly float $pollInterval = 0.1,
        private readonly array $binaryDirectories = [],
        private readonly bool $removeLogFileOnStop = false,
    ) {
    }

    /**
     * @param non-empty-string $server
     */
    public static function defaultLogFile(string $server): string
    {
        return sprintf('%s/vanta-temporal-%s-%d.log', sys_get_temp_dir(), $server, getmypid());
    }

    /**
     * @param non-empty-list<string> $command
     * @param Closure(string): bool  $isReady receives the resolved binary path
     *
     * @throws ServerStartFailed
     */
    public function start(array $command, ?string $workingDirectory, Closure $isReady): void
    {
        if ($this->isRunning()) {
            $this->logger->debug(sprintf('%s is already running', $this->name));

            return;
        }

        $binary     = $this->resolveBinary($command[0]);
        $command[0] = $binary;

        $logDirectory = dirname($this->logFile);

        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0o777, true);
        }

        $commandLine = implode(' ', array_map(escapeshellarg(...), $command));

        // Something already answers on the server's address: our process would fail to bind,
        // while the readiness probe would report the foreign server as ours
        if ($this->isServedByAnotherProcess($isReady, $binary)) {
            $this->logger->error(sprintf('%s is already running outside of this process', $this->name), ['command' => $commandLine]);

            throw ServerStartFailed::alreadyRunning($this->name, $commandLine);
        }

        clearstatcache(true, $this->logFile);

        // The log is appended to; only the output of this run belongs to error messages
        $this->logOffset = is_file($this->logFile) ? (int) filesize($this->logFile) : 0;

        $this->logger->info(sprintf('Starting %s', $this->name), [
            'command' => $commandLine,
            'cwd'     => $workingDirectory,
            'log'     => $this->logFile,
        ]);

        // An array command keeps Symfony from expanding "${:NAME}" placeholders inside arguments;
        // "exec" in sh replaces the shell, so signals reach the server binary itself
        $process = new Process(
            ['sh', '-c', 'exec "$@" >> "$0" 2>&1', $this->logFile, ...$command],
            $workingDirectory,
            $this->env,
            null,
            null,
        );

        $process->disableOutput();
        $process->start();

        $this->process = $process;

        $this->registerShutdownHook();

        $startedAt = microtime(true);
        $deadline  = $startedAt + $this->startTimeout;
        $attempt   = 0;

        while (true) {
            if (!$process->isRunning()) {
                $this->process = null;
                $this->forget();
                $logTail = $this->logTail();

                $this->logger->error(sprintf('%s exited during startup', $this->name), [
                    'exitCode' => $process->getExitCode(),
                    'logTail'  => $logTail,
                ]);

                throw ServerStartFailed::exited($commandLine, $process->getExitCode(), $this->logFile, $logTail);
            }

            ++$attempt;

            if ($isReady($binary)) {
                $this->logger->info(sprintf('%s is ready', $this->name), [
                    'elapsed'  => round(microtime(true) - $startedAt, 3),
                    'attempts' => $attempt,
                ]);

                return;
            }

            $this->logger->debug(sprintf('%s is not ready yet', $this->name), ['attempt' => $attempt]);

            if (microtime(true) >= $deadline) {
                $process->stop($this->stopTimeout);

                $this->process = null;
                $this->forget();
                $logTail = $this->logTail();

                $this->logger->error(sprintf('%s did not become ready in time', $this->name), [
                    'timeout' => $this->startTimeout,
                    'logTail' => $logTail,
                ]);

                throw ServerStartFailed::notReady($commandLine, $this->startTimeout, $this->logFile, $logTail);
            }

            usleep((int) ($this->pollInterval * 1_000_000));
        }
    }

    public function stop(): void
    {
        $this->signalStop();
        $this->waitStopped();
    }

    /**
     * Sends SIGTERM without waiting, so several servers can shut down at the same time.
     */
    public function signalStop(): void
    {
        if ($this->process == null || $this->stopDeadline != null) {
            return;
        }

        if (!$this->process->isRunning()) {
            $this->logger->info(sprintf('%s has already exited', $this->name), ['exitCode' => $this->process->getExitCode()]);

            $this->process = null;
            $this->forget();

            return;
        }

        $this->logger->info(sprintf('Stopping %s', $this->name));

        $this->stopDeadline = microtime(true) + $this->stopTimeout;

        try {
            $this->process->signal(self::SIGTERM);
        } catch (Throwable $e) {
            // The process may exit between isRunning() and signal(): waitStopped() settles it
            $this->logger->debug(sprintf('Failed to signal %s', $this->name), ['exception' => $e]);
        }
    }

    /**
     * Waits for the process to exit after {@see signalStop()}; kills it when stopTimeout passes.
     */
    public function waitStopped(): void
    {
        if ($this->process == null) {
            return;
        }

        if ($this->stopDeadline == null) {
            $this->signalStop();
        }

        $process = $this->process;

        if ($process == null) {
            return;
        }

        while ($process->isRunning() && microtime(true) < (float) $this->stopDeadline) {
            usleep(10_000);
        }

        if ($process->isRunning()) {
            $this->logger->warning(sprintf('%s did not stop within %.1f seconds, killing it', $this->name, $this->stopTimeout));

            $process->stop(0);
        }

        $this->process      = null;
        $this->stopDeadline = null;
        $this->forget();

        $this->logger->info(sprintf('%s stopped', $this->name), ['exitCode' => $process->getExitCode()]);

        if ($this->removeLogFileOnStop && is_file($this->logFile)) {
            @unlink($this->logFile);

            $this->logger->debug(sprintf('Removed %s log file', $this->name), ['log' => $this->logFile]);
        }
    }

    public function isRunning(): bool
    {
        return $this->process?->isRunning() === true;
    }

    /**
     * @param Closure(string): bool $isReady
     */
    private function isServedByAnotherProcess(Closure $isReady, string $binary): bool
    {
        return $isReady($binary);
    }

    /**
     * @throws ServerStartFailed
     */
    private function resolveBinary(string $binary): string
    {
        $resolved = str_contains($binary, '/') ? $binary : (new ExecutableFinder())->find($binary, null, $this->binaryDirectories);

        if ($resolved == null || !is_file($resolved) || !is_executable($resolved)) {
            $this->logger->error(sprintf('%s binary not found', $this->name), [
                'binary'            => $binary,
                'binaryDirectories' => $this->binaryDirectories,
            ]);

            throw ServerStartFailed::binaryNotFound($binary);
        }

        $this->logger->debug(sprintf('Resolved %s binary', $this->name), [
            'binary'            => $resolved,
            'binaryDirectories' => $this->binaryDirectories,
        ]);

        return $resolved;
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

                foreach (iterator_to_array(self::$started, false) as $server) {
                    $server->stopOnShutdown();
                }
            });

            $this->logger->debug(sprintf('Registered shutdown hook for %s', $this->name));
        }

        self::$started->attach($this);
    }

    private function stopOnShutdown(): void
    {
        try {
            $this->stop();
        } catch (Throwable $e) {
            $this->logger->error(sprintf('Failed to stop %s on shutdown', $this->name), ['exception' => $e]);
        }
    }

    private function forget(): void
    {
        self::$started?->detach($this);
    }

    private function logTail(): string
    {
        $handle = @fopen($this->logFile, 'r');

        if ($handle === false) {
            return '';
        }

        try {
            $size = fstat($handle)['size'] ?? 0;

            fseek($handle, max($this->logOffset, $size - self::LOG_TAIL_BYTES));

            return (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }
}
