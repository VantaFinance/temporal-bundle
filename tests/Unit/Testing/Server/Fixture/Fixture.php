<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class Fixture
{
    public static function binary(string $name): string
    {
        return __DIR__ . '/' . $name;
    }

    public static function temporaryDirectory(): string
    {
        $directory = sprintf('%s/vanta-temporal-test-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));

        mkdir($directory, 0o777, true);

        return (string) realpath($directory);
    }

    public static function removeDirectory(string $directory): void
    {
        (new Filesystem())->remove($directory);
    }

    public static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');

        if ($server === false) {
            throw new RuntimeException('Unable to allocate a free port');
        }

        $name = (string) stream_socket_get_name($server, false);

        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    public static function readPid(string $pidFile): int
    {
        return (int) file_get_contents($pidFile);
    }

    public static function isProcessAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    public static function waitUntilGone(int $pid, float $timeout = 2.0): bool
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            if (!self::isProcessAlive($pid)) {
                return true;
            }

            usleep(20_000);
        }

        return !self::isProcessAlive($pid);
    }
}
