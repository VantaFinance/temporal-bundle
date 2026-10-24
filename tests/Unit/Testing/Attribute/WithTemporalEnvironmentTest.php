<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Attribute;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalServerType;

#[WithTemporalEnvironment(roadRunnerConfig: 'parent.yaml')]
abstract class AttributedParent
{
}

final class InheritsAttribute extends AttributedParent
{
    public function plain(): void
    {
    }

    #[WithTemporalEnvironment(temporal: TemporalServerType::External, roadRunnerConfig: 'method.yaml')]
    public function overridden(): void
    {
    }
}

#[WithTemporalEnvironment(roadRunnerConfig: 'class.yaml')]
final class OwnAttribute
{
    public function plain(): void
    {
    }
}

final class NoAttribute
{
    public function plain(): void
    {
    }
}

#[CoversClass(WithTemporalEnvironment::class)]
final class WithTemporalEnvironmentTest extends TestCase
{
    public function testMethodAttributeOverridesClass(): void
    {
        $attribute = WithTemporalEnvironment::resolve(InheritsAttribute::class, 'overridden');

        assertNotNull($attribute);
        assertSame('method.yaml', $attribute->roadRunnerConfig);
        assertSame(TemporalServerType::External, $attribute->temporal);
    }

    public function testClassAttribute(): void
    {
        assertSame('class.yaml', WithTemporalEnvironment::resolve(OwnAttribute::class, 'plain')?->roadRunnerConfig);
    }

    public function testAttributeIsInheritedFromParent(): void
    {
        assertSame('parent.yaml', WithTemporalEnvironment::resolve(InheritsAttribute::class, 'plain')?->roadRunnerConfig);
    }

    public function testUnknownMethodFallsBackToClass(): void
    {
        assertSame('class.yaml', WithTemporalEnvironment::resolve(OwnAttribute::class, 'plain with data set #0')?->roadRunnerConfig);
    }

    public function testNoAttribute(): void
    {
        assertNull(WithTemporalEnvironment::resolve(NoAttribute::class, 'plain'));
    }

    public function testDefaults(): void
    {
        $attribute = new WithTemporalEnvironment();

        assertSame(TemporalServerType::Dev, $attribute->temporal);
        assertSame('127.0.0.1:7233', $attribute->temporalAddress);
        assertSame('.rr.temporal.testing.yaml', $attribute->roadRunnerConfig);
        assertSame([], $attribute->searchAttributes);
        assertSame('default', $attribute->temporalNamespace);
        assertSame([], $attribute->temporalDynamicConfig);
        assertSame([], $attribute->temporalArguments);
    }
}
