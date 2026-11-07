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
use Symfony\Component\Process\Process;
use Throwable;

/**
 * RoadRunner started with `rr serve`.
 */
final readonly class RoadRunnerServer implements Server, StopsInTwoPhases
{
    private ProcessServer $process;

    /**
     * @param array<string, string>           $env       extra variables for the RoadRunner process (the parent env is inherited)
     * @param array<non-empty-string, string> $overrides config values passed as `-o key=value`
     * @param list<string>                    $binaryDirectories searched after PATH when $binary is a bare name
     */
    public function __construct(
        private string $configFile,
        private string $binary = 'rr',
        private ?string $workingDirectory = null,
        private array $env = [],
        private array $overrides = [],
        ?string $logFile = null,
        float $startTimeout = 30.0,
        float $stopTimeout = 10.0,
        array $binaryDirectories = [],
        private Logger $logger = new NullLogger(),
    ) {
        $this->process = new ProcessServer(
            'RoadRunner',
            $env,
            $logFile ?? ProcessServer::defaultLogFile('rr'),
            $startTimeout,
            $stopTimeout,
            $logger,
            binaryDirectories: $binaryDirectories,
            removeLogFileOnStop: $logFile === null,
        );
    }

    public function start(): void
    {
        if ($this->process->isRunning()) {
            $this->logger->debug('RoadRunner is already running');

            return;
        }

        // RoadRunner resolves "-c" relative to "-w", so the config path must be absolute
        $config = realpath($this->configFile);

        if ($config === false) {
            $this->logger->error('RoadRunner config not found', ['config' => $this->configFile]);

            throw ServerStartFailed::configNotFound($this->configFile);
        }

        $workingDirectory = $this->workingDirectory ?? dirname($config);
        $arguments        = ['-c', $config, '-w', $workingDirectory];

        foreach ($this->overrides as $key => $value) {
            $arguments[] = '-o';
            $arguments[] = sprintf('%s=%s', $key, $value);
        }

        $this->logger->debug('Resolved RoadRunner config', [
            'config'           => $config,
            'workingDirectory' => $workingDirectory,
        ]);

        $this->process->start(
            [$this->binary, 'serve', ...$arguments],
            $workingDirectory,
            fn (string $binary): bool => $this->isReady($binary, $arguments, $workingDirectory),
        );
    }

    public function stop(): void
    {
        $this->process->stop();
    }

    public function signalStop(): void
    {
        $this->process->signalStop();
    }

    public function waitStopped(): void
    {
        $this->process->waitStopped();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * @param list<string> $arguments
     */
    private function isReady(string $binary, array $arguments, string $workingDirectory): bool
    {
        $check = new Process([$binary, 'workers', ...$arguments], $workingDirectory, $this->env, timeout: 5);

        try {
            $check->run();
        } catch (Throwable) {
            return false;
        }

        return str_contains($check->getOutput(), 'Workers of');
    }
}
