<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

/**
 * Starts a test environment with fake RoadRunner and exits without calling stop().
 * Usage: php start-and-exit.php <fake-rr binary> <absolute config file>
 */

use Vanta\Integration\Symfony\Temporal\Testing\Server\RoadRunnerServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

require dirname(__DIR__, 5) . '/vendor/autoload.php';

$environment = new TemporalTestEnvironment(
    new RoadRunnerServer(binary: (string) $argv[1], configFile: (string) $argv[2], startTimeout: 5.0),
);

$environment->start();

echo "started\n";
