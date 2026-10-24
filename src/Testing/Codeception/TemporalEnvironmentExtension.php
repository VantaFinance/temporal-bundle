<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Codeception;

use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Test\Cest;
use Codeception\Test\TestCaseWrapper;
use Codeception\TestInterface;
use Psr\Log\NullLogger;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Server\AttributeLifecycle;
use Vanta\Integration\Symfony\Temporal\Testing\Server\FileLogger;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

/**
 * Starts Temporal and RoadRunner before each test marked with {@see WithTemporalEnvironment} and stops them after it.
 *
 *     extensions:
 *         enabled:
 *             - Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalEnvironmentExtension:
 *                 logFile: var/log/test-environment.log   # optional
 */
final class TemporalEnvironmentExtension extends Extension
{
    /**
     * @var array<string, string>
     */
    public static array $events = [
        Events::TEST_BEFORE => 'testBefore',
        Events::TEST_AFTER  => 'testAfter',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $config = ['logFile' => null];

    private ?AttributeLifecycle $lifecycle = null;

    public function testBefore(TestEvent $event): void
    {
        $target = self::target($event->getTest());

        if ($target == null) {
            return;
        }

        $this->lifecycle()->start(...$target);
    }

    public function testAfter(TestEvent $event): void
    {
        $this->lifecycle()->stop();
    }

    public function current(): ?TemporalTestEnvironment
    {
        return $this->lifecycle?->current();
    }

    /**
     * @return array{class-string, string}|null
     */
    private static function target(TestInterface $test): ?array
    {
        if ($test instanceof Cest) {
            return [$test->getTestInstance()::class, $test->getTestMethod()];
        }

        if ($test instanceof TestCaseWrapper) {
            $testCase = $test->getTestCase();

            return [$testCase::class, $testCase->name()];
        }

        return null;
    }

    private function lifecycle(): AttributeLifecycle
    {
        if ($this->lifecycle == null) {
            $logFile = $this->config['logFile'] ?? null;

            $this->lifecycle = new AttributeLifecycle(
                is_string($logFile) && $logFile !== '' ? new FileLogger($logFile) : new NullLogger(),
            );
        }

        return $this->lifecycle;
    }
}
