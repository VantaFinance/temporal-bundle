<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e;

use Symfony\Component\Filesystem\Filesystem;
use Vanta\Integration\Symfony\Temporal\Test\App\Kernel;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

/**
 * Every test gets its own Temporal dev server and RoadRunner: TemporalEnvironmentExtension (phpunit.xml) reads the attribute below.
 */
#[WithTemporalEnvironment(roadRunnerConfig: 'tests/App/.rr.temporal.yaml')]
abstract class E2eTestCase extends TemporalTestCase
{
    private static bool $cacheCleared = false;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);

        if (!is_executable($root . '/temporal') || !is_executable($root . '/rr')) {
            self::markTestSkipped('Run `make get-deps` to download rr and temporal');
        }

        if (!self::$cacheCleared) {
            // The RoadRunner worker runs the test kernel in "prod" (no cache auto-refresh): drop a stale container
            (new Filesystem())->remove($root . '/var/cache/prod');

            self::$cacheCleared = true;
        }

        // RoadRunner writes its worker log (logs.output) there on its own, not through FileLogger
        if (!is_dir($root . '/var/log')) {
            mkdir($root . '/var/log', 0o777, true);
        }

        parent::setUp();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }
}
