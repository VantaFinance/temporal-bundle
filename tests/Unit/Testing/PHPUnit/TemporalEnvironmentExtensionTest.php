<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\PHPUnit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

/**
 * A plain PHPUnit TestCase: no trait, no base class — the extension from phpunit.xml reads the attribute.
 */
#[CoversClass(TemporalEnvironmentExtension::class)]
#[WithTemporalEnvironment(
    temporalAddress: '127.0.0.1:17233',
    temporalBinary: 'tests/Unit/Testing/Server/Fixture/fake-temporal',
    roadRunnerConfig: 'tests/Unit/Testing/Server/Fixture/attribute/rr.yaml',
    roadRunnerBinary: 'tests/Unit/Testing/Server/Fixture/fake-rr',
    startTimeout: 5.0,
)]
final class TemporalEnvironmentExtensionTest extends TestCase
{
    private static ?TemporalTestEnvironment $previous = null;

    protected function setUp(): void
    {
        TemporalEnvironmentExtension::rethrowStartFailure();
    }

    public function testEnvironmentRunsDuringTest(): void
    {
        $environment = TemporalEnvironmentExtension::current();

        assertNotNull($environment);
        assertTrue($environment->isRunning());

        self::$previous = $environment;
    }

    #[Depends('testEnvironmentRunsDuringTest')]
    public function testPreviousEnvironmentWasStoppedAndThisTestHasItsOwn(): void
    {
        assertNotNull(self::$previous);
        assertFalse(self::$previous->isRunning());
        assertTrue(TemporalEnvironmentExtension::current()?->isRunning() === true);
        assertNotSame(self::$previous, TemporalEnvironmentExtension::current());
    }
}
