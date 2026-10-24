<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

/**
 * Lets {@see TemporalTestEnvironment} signal all servers first and then wait for them, instead of one after another.
 *
 * @internal
 */
interface StopsInTwoPhases
{
    public function signalStop(): void;

    public function waitStopped(): void;
}
