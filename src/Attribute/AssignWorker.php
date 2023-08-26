<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AssignWorker
{
    public function __construct(
        public string $name,
    ) {
    }
}
