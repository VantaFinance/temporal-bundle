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

// $argv exists only with register_argc_argv enabled
$arguments  = $_SERVER['argv'] ?? [];
$binary     = is_array($arguments) ? $arguments[1] ?? null : null;
$configFile = is_array($arguments) ? $arguments[2] ?? null : null;

if (!is_string($binary) || !is_string($configFile)) {
    fwrite(STDERR, "Usage: php start-and-exit.php <fake-rr binary> <absolute config file>\n");

    exit(2);
}

$environment = new TemporalTestEnvironment(
    new RoadRunnerServer(binary: $binary, configFile: $configFile, startTimeout: 5.0),
);

$environment->start();

echo "started\n";
