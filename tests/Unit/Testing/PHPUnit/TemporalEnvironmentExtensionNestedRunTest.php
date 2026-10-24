<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\PHPUnit;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension;

/**
 * Runs Fixture/Scenarios.php in a separate PHPUnit process with the extension enabled and checks
 * that every scenario (also failing setUp, skips and unmet requirements) stops its servers.
 */
#[CoversClass(TemporalEnvironmentExtension::class)]
final class TemporalEnvironmentExtensionNestedRunTest extends TestCase
{
    private const LOG = 'tests/Unit/Testing/PHPUnit/Fixture/nested/test-environment.log';

    public function testEveryScenarioStopsItsServers(): void
    {
        $root = dirname(__DIR__, 4);

        @unlink($root . '/' . self::LOG);

        $process = new Process(
            [
                (string) (new PhpExecutableFinder())->find(false),
                $root . '/vendor/bin/phpunit',
                '-c',
                $root . '/tests/Unit/Testing/PHPUnit/Fixture/phpunit.xml',
                '--do-not-cache-result',
                '--colors=never',
            ],
            $root,
            timeout: 120,
        );
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        // 6 tests: 1 passed, 1 failure, 2 errors (body, setUp), 2 skipped
        assertStringContainsString('Tests: 6', $output, $output);

        $log = (string) file_get_contents($root . '/' . self::LOG);

        assertSame(6, substr_count($log, 'Starting test environment for'), $log);
        assertSame(6, substr_count($log, ': Test environment stopped'), $log);

        $alive = new Process(['pgrep', '-fl', 'tests/Unit/Testing/PHPUnit/Fixture/nested/rr.yaml|--port 17236']);
        $alive->run();

        assertSame('', trim($alive->getOutput()), 'Fake servers left running: ' . $alive->getOutput());
    }
}
