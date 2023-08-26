<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

interface Finalizer
{
    public function finalize(): void;
}
