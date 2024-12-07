<?php

declare(strict_types=1);

namespace Unit\Finalizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Finalizer\ChainFinalizer;
use Vanta\Integration\Symfony\Temporal\Finalizer\Finalizer;

#[CoversClass(ChainFinalizer::class)]
final class ChainFinalizerTest extends TestCase
{
    /**
     * @throws Exception
     */
    public function testFinalizeCallsAllFinalizers(): void
    {
        // Create mocks for the Finalizer interface
        $finalizer1 = $this->createMock(Finalizer::class);
        $finalizer2 = $this->createMock(Finalizer::class);

        // Expect the finalize method to be called once on each finalizer
        $finalizer1->expects($this->once())->method('finalize');
        $finalizer2->expects($this->once())->method('finalize');

        // Create the ChainFinalizer with the mocked finalizers
        $chainFinalizer = new ChainFinalizer([$finalizer1, $finalizer2]);

        // Call the finalize method
        $chainFinalizer->finalize();
    }
}
