<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Finalizers;

use Vanta\Integration\Symfony\Temporal\Finalizer\Finalizer;

class DummyFinalizer implements Finalizer
{
    public function finalize(): void
    {
    }
}
