<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Tools;

use Carbon\CarbonInterval;

use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;

use function Vanta\Integration\Symfony\Temporal\Testing\Tools\awaitWithTimeout;
use function Vanta\Integration\Symfony\Temporal\Testing\Tools\waitWithTime;

#[CoversFunction('Vanta\Integration\Symfony\Temporal\Testing\Tools\awaitWithTimeout')]
#[CoversFunction('Vanta\Integration\Symfony\Temporal\Testing\Tools\waitWithTime')]
final class ToolsTest extends TestCase
{
    public function testAwaitWithTimeoutPollsUntilTimeout(): void
    {
        $calls = 0;

        awaitWithTimeout(static function () use (&$calls): bool {
            ++$calls;

            return false;
        }, CarbonInterval::milliseconds(300), 0.05);

        assertGreaterThan(1, $calls);
    }

    public function testAwaitWithTimeoutStopsOnCondition(): void
    {
        $calls = 0;

        awaitWithTimeout(static function () use (&$calls): bool {
            return ++$calls == 3;
        }, CarbonInterval::seconds(5), 0.01);

        assertSame(3, $calls);
    }

    public function testWaitWithTimePollsUntilTimeout(): void
    {
        $calls = 0;

        waitWithTime(static function () use (&$calls): bool {
            ++$calls;

            return false;
        }, CarbonInterval::seconds(2), 1);

        assertGreaterThan(1, $calls);
    }
}
