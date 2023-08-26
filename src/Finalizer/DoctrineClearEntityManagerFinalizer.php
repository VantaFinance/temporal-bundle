<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

use Doctrine\Persistence\ManagerRegistry;

final readonly class DoctrineClearEntityManagerFinalizer implements Finalizer
{
    public function __construct(
        private ManagerRegistry $managerRegistry
    ) {
    }


    public function finalize(): void
    {
        foreach ($this->managerRegistry->getManagers() as $manager) {
            $manager->clear();
        }
    }
}
