<?php

declare(strict_types=1);

namespace Unit\Finalizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\Finalizer\Finalizer;

#[CoversClass(ChainFinalizer::class)]
final class ChainFinalizerTest extends TestCase
{
    public function testFinalizeCallsAllFinalizers(): void
    {
        $this->expectOutputString(
            'Unit\Finalizer\DummyFinalizer::finalize' . PHP_EOL .
            'Unit\Finalizer\DummyFinalizer::finalize' . PHP_EOL
        );

        $finalizer1 = new class() extends DummyFinalizer {};
        $finalizer2 = new class() extends DummyFinalizer {};

        (new ChainFinalizer([$finalizer1, $finalizer2]))->finalize();
    }
}



abstract class DummyFinalizer implements Finalizer
{
    final public function finalize(): void
    {
        echo self::class . '::' . __FUNCTION__ . PHP_EOL;
    }
}
