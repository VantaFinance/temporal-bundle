<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\Fixture;
use Vanta\Integration\Symfony\Temporal\Testing\Server\FileLogger;

#[CoversClass(FileLogger::class)]
final class FileLoggerTest extends TestCase
{
    public function testCreatesMissingLogDirectory(): void
    {
        $directory = Fixture::temporaryDirectory();
        $file      = $directory . '/var/log/test-environment.log';

        try {
            (new FileLogger($file))->info('Starting test environment');

            assertFileExists($file);
            assertStringContainsString('info: Starting test environment', (string) file_get_contents($file));
        } finally {
            Fixture::removeDirectory($directory);
        }
    }
}
