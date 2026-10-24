<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

enum TemporalServerType: string
{
    /**
     * `temporal server start-dev` ({@see TemporalDevServer}).
     */
    case Dev = 'dev';

    /**
     * Time-skipping `temporal-test-server` ({@see TemporalTestServer}).
     */
    case TimeSkipping = 'time-skipping';

    /**
     * No Temporal process is started: an already running server is used.
     */
    case External = 'external';
}
