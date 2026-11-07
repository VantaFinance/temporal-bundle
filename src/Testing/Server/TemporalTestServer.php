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
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Temporal\Testing\Downloader;
use Temporal\Testing\SystemInfo;

/**
 * Time-skipping Temporal test server (`temporal-test-server`), required by {@see \Temporal\Testing\TestService}.
 */
final readonly class TemporalTestServer implements Server, StopsInTwoPhases
{
    private ProcessServer $process;

    /**
     * @var non-empty-list<string>
     */
    private array $command;

    /**
     * @param list<string> $binaryDirectories searched after PATH when $binary is a bare name
     */
    public function __construct(
        string $binary = 'temporal-test-server',
        private int $port = 7233,
        bool $timeSkipping = true,
        ?string $logFile = null,
        float $startTimeout = 10.0,
        float $stopTimeout = 10.0,
        array $binaryDirectories = [],
        Logger $logger = new NullLogger(),
    ) {
        $this->command = $timeSkipping
            ? [$binary, (string) $port, '--enable-time-skipping']
            : [$binary, (string) $port];

        $this->process = new ProcessServer(
            'Temporal test server',
            [],
            $logFile ?? ProcessServer::defaultLogFile('temporal-test'),
            $startTimeout,
            $stopTimeout,
            $logger,
            binaryDirectories: $binaryDirectories,
            removeLogFileOnStop: $logFile === null,
        );
    }

    /**
     * Downloads the temporal-test-server binary into $directory unless it is already there.
     *
     * @return string path to the binary
     */
    public static function download(string $directory, ?string $githubToken = null, Logger $logger = new NullLogger()): string
    {
        $systemInfo = SystemInfo::detect();
        $binary     = $directory . DIRECTORY_SEPARATOR . basename($systemInfo->temporalServerExecutable);

        if (is_file($binary)) {
            $logger->info('temporal-test-server is already present', ['binary' => $binary]);

            return $binary;
        }

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $logger->info(sprintf('Downloading temporal-test-server to %s', $directory));

        $headers = $githubToken == null ? [] : ['authorization' => 'token ' . $githubToken];
        $cwd     = getcwd();

        if ($cwd === false) {
            throw new RuntimeException('Unable to determine the current working directory.');
        }

        // The SDK Downloader always writes into getcwd() and has no target directory parameter
        chdir($directory);

        try {
            (new Downloader(new Filesystem(), HttpClient::create(['headers' => $headers])))->download($systemInfo);
        } finally {
            chdir($cwd);
        }

        $logger->info('temporal-test-server downloaded', ['binary' => $binary]);

        return $binary;
    }

    public function start(): void
    {
        $this->process->start($this->command, null, $this->isReady(...));
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

    private function isReady(): bool
    {
        $connection = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.2);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
