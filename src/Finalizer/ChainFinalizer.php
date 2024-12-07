<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

final readonly class ChainFinalizer implements Finalizer
{
    /**
     * @param iterable<Finalizer> $finalizers
     */
    public function __construct(
        private iterable $finalizers,
    ) {
    }

    public function finalize(): void
    {
        foreach ($this->finalizers as $finalizer) {
            $finalizer->finalize();
        }
    }
}
