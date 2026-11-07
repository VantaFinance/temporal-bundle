<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\PHPUnit;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;
use PHPUnit\Event\Test\PreparationFailed;
use PHPUnit\Event\Test\PreparationFailedSubscriber;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Psr\Log\NullLogger;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Server\AttributeLifecycle;
use Vanta\Integration\Symfony\Temporal\Testing\Server\FileLogger;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

/**
 * Starts Temporal and RoadRunner before each test marked with {@see WithTemporalEnvironment} (before setUp())
 * and stops them after it (after tearDown()).
 *
 *     <extensions>
 *         <bootstrap class="Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension">
 *             <parameter name="logFile" value="var/log/test-environment.log"/>
 *         </bootstrap>
 *     </extensions>
 */
final class TemporalEnvironmentExtension implements Extension
{
    private static ?AttributeLifecycle $lifecycle = null;
    private static ?Throwable $startFailure       = null;

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $logger = $parameters->has('logFile') ? new FileLogger($parameters->get('logFile')) : new NullLogger();

        $lifecycle       = new AttributeLifecycle($logger);
        self::$lifecycle = $lifecycle;

        $facade->registerSubscriber(new class($lifecycle) implements PreparationStartedSubscriber {
            public function __construct(
                private readonly AttributeLifecycle $lifecycle,
            ) {
            }

            public function notify(PreparationStarted $event): void
            {
                TemporalEnvironmentExtension::start($this->lifecycle, $event->test());
            }
        });

        $stop = new class($lifecycle) implements FinishedSubscriber, SkippedSubscriber, ErroredSubscriber {
            public function __construct(
                private readonly AttributeLifecycle $lifecycle,
            ) {
            }

            public function notify(Finished|Skipped|Errored $event): void
            {
                $this->lifecycle->stop();
            }
        };

        $facade->registerSubscriber($stop);

        // PHPUnit 10.0 has no PreparationFailed: a failing setUp() ends with Errored there
        if (interface_exists(PreparationFailedSubscriber::class)) {
            $facade->registerSubscriber(new class($lifecycle) implements PreparationFailedSubscriber {
                public function __construct(
                    private readonly AttributeLifecycle $lifecycle,
                ) {
                }

                public function notify(PreparationFailed $event): void
                {
                    $this->lifecycle->stop();
                }
            });
        }
    }

    /**
     * The environment of the running test, or null when the test has no {@see WithTemporalEnvironment}.
     */
    public static function current(): ?TemporalTestEnvironment
    {
        return self::$lifecycle?->current();
    }

    /**
     * PHPUnit turns exceptions of event subscribers into warnings; call this from setUp() to fail the test instead.
     *
     * @throws Throwable
     */
    public static function rethrowStartFailure(): void
    {
        $failure            = self::$startFailure;
        self::$startFailure = null;

        if ($failure != null) {
            throw $failure;
        }
    }

    /**
     * @internal
     */
    public static function start(AttributeLifecycle $lifecycle, \PHPUnit\Event\Code\Test $test): void
    {
        self::$startFailure = null;

        if (!$test instanceof TestMethod) {
            return;
        }

        try {
            /** @var class-string $class */
            $class = $test->className();

            $lifecycle->start($class, $test->methodName());
        } catch (Throwable $e) {
            self::$startFailure = $e;

            throw $e;
        }
    }
}
