<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App\Workflow;

use DateTimeImmutable;

final readonly class TestQuery
{
    public function __construct(
        public string $name,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
