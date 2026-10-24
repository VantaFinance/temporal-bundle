<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

use Symfony\Contracts\Service\ResetInterface as Reseter;

final readonly class FrameworkFinalizer implements Finalizer
{
    public function __construct(
        private Reseter $reseter,
    ) {
    }


    public function finalize(): void
    {
        $this->reseter->reset();
    }
}
