<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use Countable;
use Temporal\Worker\WorkerFactoryInterface as WorkerFactory;
use Temporal\Worker\WorkerInterface as Worker;

final readonly class Runtime implements Countable
{
    /**
     * @param array<int, Worker> $workers
     */
    public function __construct(
        private WorkerFactory $factory,
        private array $workers,
    ) {
    }



    public function run(): void
    {
        $this->factory->run();
    }

    public function count(): int
    {
        return count($this->workers);
    }
}
