<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\PHPUnit;

use function PHPUnit\Framework\assertNull;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension;

#[CoversClass(TemporalEnvironmentExtension::class)]
final class WithoutTemporalEnvironmentTest extends TestCase
{
    public function testNothingStartsWithoutAttribute(): void
    {
        assertNull(TemporalEnvironmentExtension::current());
    }
}
