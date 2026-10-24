<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use RuntimeException;

final class ServerStartFailed extends RuntimeException
{
    public static function binaryNotFound(string $binary): self
    {
        return new self(sprintf('Server binary "%s" does not exist or is not executable.', $binary));
    }

    public static function configNotFound(string $configFile): self
    {
        return new self(sprintf('Server config file "%s" does not exist.', $configFile));
    }

    public static function alreadyRunning(string $name, string $commandLine): self
    {
        return new self(sprintf(
            "%s is already answering on its address before it was started: another process is running there. Stop it or use another port.\nCommand: %s",
            $name,
            $commandLine,
        ));
    }

    public static function exited(string $commandLine, ?int $exitCode, string $logFile, string $logTail): self
    {
        return new self(sprintf(
            "Server exited during startup with code %s.\nCommand: %s\nLog file: %s\nLog tail:\n%s",
            $exitCode ?? 'unknown',
            $commandLine,
            $logFile,
            $logTail,
        ));
    }

    public static function notReady(string $commandLine, float $timeout, string $logFile, string $logTail): self
    {
        return new self(sprintf(
            "Server did not become ready within %.1f seconds.\nCommand: %s\nLog file: %s\nLog tail:\n%s",
            $timeout,
            $commandLine,
            $logFile,
            $logTail,
        ));
    }
}
