<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use Temporal\Worker\WorkerFactoryInterface as WorkerFactory;
use Temporal\Worker\WorkerInterface as Worker;

final readonly class Runtime
{
    /**
     * @param array<int, Worker> $workers
     */
    public function __construct(
        private WorkerFactory $factory,
        /** @noinspection */
        /** @phpstan-ignore-next-line */
        private array $workers,
    ) {
    }


    public function run(): void
    {
        $this->factory->run();
    }
}
