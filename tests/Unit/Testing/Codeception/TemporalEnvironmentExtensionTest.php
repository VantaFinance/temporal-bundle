<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Codeception;

use Codeception\Event\TestEvent;
use Codeception\Test\Cest;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalEnvironmentExtension;

#[WithTemporalEnvironment(
    temporalAddress: '127.0.0.1:17234',
    temporalBinary: 'tests/Unit/Testing/Server/Fixture/fake-temporal',
    roadRunnerConfig: 'tests/Unit/Testing/Server/Fixture/attribute/rr.yaml',
    roadRunnerBinary: 'tests/Unit/Testing/Server/Fixture/fake-rr',
    startTimeout: 5.0,
)]
final class AttributedCest
{
    public function tryWorkflow(): void
    {
    }
}

final class PlainCest
{
    public function tryWorkflow(): void
    {
    }
}

#[CoversClass(TemporalEnvironmentExtension::class)]
final class TemporalEnvironmentExtensionTest extends TestCase
{
    public function testStartsBeforeAndStopsAfterAttributedCest(): void
    {
        $extension = new TemporalEnvironmentExtension([], []);
        $event     = new TestEvent(new Cest(new AttributedCest(), 'tryWorkflow', __FILE__));

        $extension->testBefore($event);

        $environment = $extension->current();

        assertNotNull($environment);
        assertTrue($environment->isRunning());

        $extension->testAfter($event);

        assertFalse($environment->isRunning());
        assertNull($extension->current());
    }

    public function testDoesNothingWithoutAttribute(): void
    {
        $extension = new TemporalEnvironmentExtension([], []);
        $event     = new TestEvent(new Cest(new PlainCest(), 'tryWorkflow', __FILE__));

        $extension->testBefore($event);

        assertNull($extension->current());

        $extension->testAfter($event);
    }
}
