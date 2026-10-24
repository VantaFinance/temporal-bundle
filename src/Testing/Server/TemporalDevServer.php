<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use InvalidArgumentException;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Symfony\Component\Process\Process;
use Temporal\Common\SearchAttributes\ValueType;
use Throwable;

/**
 * Temporal dev server started through the Temporal CLI: `temporal server start-dev`.
 */
final readonly class TemporalDevServer implements Server, StopsInTwoPhases
{
    private ProcessServer $process;

    /**
     * @var non-empty-list<string>
     */
    private array $command;

    /**
     * @param array<non-empty-string, ValueType> $searchAttributes
     * @param array<string, mixed>               $dynamicConfig     passed as `--dynamic-config-value KEY=<json>`
     * @param list<string>                       $extraArguments    appended last, so they override the built-in flags
     * @param list<string>                       $binaryDirectories searched after PATH when $binary is a bare name
     */
    public function __construct(
        string $binary = 'temporal',
        private string $address = '127.0.0.1:7233',
        string $namespace = 'default',
        array $searchAttributes = [],
        array $dynamicConfig = [],
        array $extraArguments = [],
        ?string $logFile = null,
        float $startTimeout = 30.0,
        float $stopTimeout = 10.0,
        array $binaryDirectories = [],
        Logger $logger = new NullLogger(),
    ) {
        $url  = parse_url('tcp://' . $address);
        $host = $url['host'] ?? null;
        $port = $url['port'] ?? null;

        if ($host == null || $port == null) {
            throw new InvalidArgumentException(sprintf('Invalid Temporal address "%s", expected "host:port".', $address));
        }

        $searchAttributeArguments = [];

        foreach ($searchAttributes as $name => $type) {
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $name) !== 1) {
                throw new InvalidArgumentException(sprintf('Invalid search attribute name "%s".', $name));
            }

            $searchAttributeArguments[] = '--search-attribute';
            $searchAttributeArguments[] = sprintf('%s=%s', $name, self::cliType($type));
        }

        $dynamicConfigArguments = [];

        foreach ($dynamicConfig as $key => $value) {
            if ($key === '') {
                throw new InvalidArgumentException('Dynamic config key must not be empty.');
            }

            $dynamicConfigArguments[] = '--dynamic-config-value';
            $dynamicConfigArguments[] = sprintf('%s=%s', $key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        $this->command = [
            $binary,
            'server',
            'start-dev',
            '--ip',
            $host,
            '--port',
            (string) $port,
            '--namespace',
            $namespace,
            '--log-level',
            'error',
            '--headless',
            ...$searchAttributeArguments,
            ...$dynamicConfigArguments,
            ...$extraArguments,
        ];

        $this->process = new ProcessServer(
            'Temporal dev server',
            [],
            $logFile ?? ProcessServer::defaultLogFile('temporal-dev'),
            $startTimeout,
            $stopTimeout,
            $logger,
            binaryDirectories: $binaryDirectories,
            removeLogFileOnStop: $logFile === null,
        );
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

    private function isReady(string $binary): bool
    {
        $check = new Process([$binary, 'operator', 'cluster', 'health', '--address', $this->address], timeout: 5);

        try {
            $check->run();
        } catch (Throwable) {
            return false;
        }

        // gRPC health also reports NOT_SERVING, so the whole line must be SERVING
        return preg_match('/^SERVING$/m', $check->getOutput()) === 1;
    }

    private static function cliType(ValueType $type): string
    {
        return match ($type) {
            ValueType::Bool        => 'Bool',
            ValueType::Float       => 'Double',
            ValueType::Int         => 'Int',
            ValueType::Keyword     => 'Keyword',
            ValueType::KeywordList => 'KeywordList',
            ValueType::Text        => 'Text',
            ValueType::Datetime    => 'Datetime',
        };
    }
}
