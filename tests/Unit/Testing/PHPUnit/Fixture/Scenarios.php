<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\PHPUnit\Fixture;

use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;

/**
 * Run only by TemporalEnvironmentExtensionNestedRunTest through Fixture/phpunit.xml (the file name has no "Test" suffix).
 */
#[CoversNothing]
#[WithTemporalEnvironment(
    temporalAddress: '127.0.0.1:17236',
    temporalBinary: 'tests/Unit/Testing/Server/Fixture/fake-temporal',
    roadRunnerConfig: 'tests/Unit/Testing/PHPUnit/Fixture/nested/rr.yaml',
    roadRunnerBinary: 'tests/Unit/Testing/Server/Fixture/fake-rr',
    startTimeout: 5.0,
)]
final class Scenarios extends TestCase
{
    protected function setUp(): void
    {
        if ($this->name() === 'testSetUpThrows') {
            throw new RuntimeException('setUp failed');
        }

        if ($this->name() === 'testSkippedInSetUp') {
            self::markTestSkipped('skipped in setUp');
        }
    }

    public function testPasses(): void
    {
        $this->addToAssertionCount(1);
    }

    public function testFails(): void
    {
        self::fail('failed');
    }

    public function testErrors(): void
    {
        throw new LogicException('errored');
    }

    public function testSetUpThrows(): void
    {
        $this->addToAssertionCount(1);
    }

    public function testSkippedInSetUp(): void
    {
        $this->addToAssertionCount(1);
    }

    #[RequiresPhpExtension('definitely_missing_extension')]
    public function testRequirementNotMet(): void
    {
        $this->addToAssertionCount(1);
    }
}
