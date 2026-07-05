<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Tools;

use function Amp\delay;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;

/**
 * @param callable(): bool $conditions
 *
 * @throws Exception
 */
function awaitWithTimeout(callable $conditions, CarbonInterval $timeout = new CarbonInterval(seconds: 5), float $delay = 2): void
{
    $wait       = true;
    $watchEndAt = CarbonImmutable::now()->add($timeout);

    while ($wait) {
        delay($delay);

        if ($conditions()) {
            $wait = false;
        }

        if (CarbonImmutable::now()->diff($watchEndAt)->totalSeconds <= 0) {
            $wait = false;
        }
    }
}


/**
 * @param callable(): bool $conditions
 *
 * @throws Exception
 */
function waitWithTime(callable $conditions, CarbonInterval $timeout = new CarbonInterval(seconds: 5), int $delay = 2): void
{
    $wait       = true;
    $watchEndAt = CarbonImmutable::now()->add($timeout);

    while ($wait) {
        sleep($delay);

        if ($conditions()) {
            $wait = false;
        }

        if (CarbonImmutable::now()->diff($watchEndAt)->totalSeconds <= 0) {
            $wait = false;
        }
    }
}
