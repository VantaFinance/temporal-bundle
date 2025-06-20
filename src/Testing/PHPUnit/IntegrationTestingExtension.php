<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\PHPUnit;

require_once __DIR__ . '/../RoadRunner/boostrap.php';

use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
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


        $facade->registerSubscriber(new class($environment) implements PreparationStartedSubscriber {
            public function __construct(
                private readonly Environment $environment,
            ) {
            }

            public function notify(PreparationStarted $event): void
            {
                boostrapTesting($this->environment);
            }
        });


        $facade->registerSubscriber(new class($environment) implements SkippedSubscriber {
            public function __construct(
                private readonly Environment $environment,
            ) {
            }

            public function notify(Skipped $event): void
            {
                $this->environment->stop();
            }
        });

        $facade->registerSubscriber(new class($environment) implements FinishedSubscriber {
            public function __construct(
                private readonly Environment $environment,
            ) {
            }

            public function notify(Finished $event): void
            {
                $this->environment->stop();
            }
        });


        $facade->registerSubscriber(new class($environment) implements ErroredSubscriber {
            public function __construct(
                private readonly Environment $environment,
            ) {
            }

            public function notify(Errored $event): void
            {
                $this->environment->stop();
            }
        });
    }
}
