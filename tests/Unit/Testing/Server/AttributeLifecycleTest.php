<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Server\AttributeLifecycle;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ServerStartFailed;

#[WithTemporalEnvironment(
    temporalAddress: '127.0.0.1:17235',
    temporalBinary: 'tests/Unit/Testing/Server/Fixture/fake-temporal',
    roadRunnerConfig: 'tests/Unit/Testing/Server/Fixture/attribute/rr.yaml',
    roadRunnerBinary: 'tests/Unit/Testing/Server/Fixture/fake-rr',
    startTimeout: 5.0,
)]
final class LifecycleAttributedFixture
{
    public function test(): void
    {
    }

    #[WithTemporalEnvironment(temporalBinary: 'tests/Unit/Testing/Server/Fixture/missing-temporal')]
    public function broken(): void
    {
    }
}

final class LifecyclePlainFixture
{
    public function test(): void
    {
    }
}

#[CoversClass(AttributeLifecycle::class)]
final class AttributeLifecycleTest extends TestCase
{
    public function testStartsAndStopsAttributedTest(): void
    {
        $lifecycle = new AttributeLifecycle();

        $lifecycle->start(LifecycleAttributedFixture::class, 'test');

        $environment = $lifecycle->current();

        assertNotNull($environment);
        assertTrue($environment->isRunning());

        $lifecycle->stop();

        assertFalse($environment->isRunning());
        assertNull($lifecycle->current());

        $lifecycle->stop();
    }

    public function testNothingStartsWithoutAttribute(): void
    {
        $lifecycle = new AttributeLifecycle();

        $lifecycle->start(LifecyclePlainFixture::class, 'test');

        assertNull($lifecycle->current());
    }

    public function testNextStartStopsPreviousEnvironment(): void
    {
        $lifecycle = new AttributeLifecycle();

        $lifecycle->start(LifecycleAttributedFixture::class, 'test');

        $previous = $lifecycle->current();

        $lifecycle->start(LifecycleAttributedFixture::class, 'test');

        assertNotNull($previous);
        assertFalse($previous->isRunning());
        assertNotSame($previous, $lifecycle->current());
        assertTrue($lifecycle->current()?->isRunning() === true);

        $lifecycle->stop();
    }

    public function testFailedStartKeepsNoEnvironment(): void
    {
        $lifecycle = new AttributeLifecycle();

        try {
            $lifecycle->start(LifecycleAttributedFixture::class, 'broken');
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed) {
        }

        assertNull($lifecycle->current());
    }
}
