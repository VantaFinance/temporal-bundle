<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface as EntityManager;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;

final readonly class DoctrinePingConnectionFinalizer implements Finalizer
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private string $entityManagerName,
    ) {
    }


    /**
     * @throws DBALException
     */
    public function finalize(): void
    {
        try {
            $entityManager = $this->managerRegistry->getManager($this->entityManagerName);
        } catch (InvalidArgumentException) {
            return;
        }

        if (!$entityManager instanceof EntityManager) {
            return;
        }

        $connection = $entityManager->getConnection();

        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (DBALException) {
            $connection->close();
            $connection->connect();
        }

        if (!$entityManager->isOpen()) {
            $this->managerRegistry->resetManager($this->entityManagerName);
        }
    }
}
