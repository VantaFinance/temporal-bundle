<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\PHPUnit;

require_once __DIR__ . '/../RoadRunner/function.php';

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Temporal\Testing\Environment;
use Throwable;

use function Vanta\Integration\Symfony\Temporal\Testing\RoadRunner\boostrapTesting;

final class IntegrationTestingExtension implements Extension
{
    /**
     * @throws Throwable
     */
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $environment = Environment::create();

        register_shutdown_function($environment->stop(...));
        boostrapTesting($environment);
    }
}
