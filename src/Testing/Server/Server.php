<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

interface Server
{
    /**
     * Starts the server and blocks until it is ready. Calling it on a running server is a no-op.
     *
     * @throws ServerStartFailed
     */
    public function start(): void;

    /**
     * Gracefully stops the server. Calling it on a stopped server is a no-op.
     */
    public function stop(): void;

    public function isRunning(): bool;
}
