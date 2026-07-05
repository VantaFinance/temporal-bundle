<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

use Vanta\Integration\Temporal\Doctrine\Finalizer\Finalizer as DoctrineIntegrationFinalizer;

final readonly class DoctrineFinalizer implements Finalizer
{
    public function __construct(
        private DoctrineIntegrationFinalizer $finalizer,
    ) {
    }

    public function finalize(): void
    {
        $this->finalizer->finalize();
    }
}
