<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\TemporalExtension;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[CoversClass(TemporalBundle::class)]
final class TemporalBundleTest extends TestCase
{
    public function testProvidesTemporalExtension(): void
    {
        $extension = (new TemporalBundle())->getContainerExtension();

        assertInstanceOf(TemporalExtension::class, $extension);
        assertSame('temporal', $extension->getAlias());
    }
}
