<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Symfony\Component\Runtime\RunnerInterface as Runner;
use Symfony\Component\Runtime\SymfonyRuntime;

final class TemporalRuntime extends SymfonyRuntime
{
    public function getRunner(?object $application): Runner
    {
        if ($application instanceof Kernel) {
            $application->boot();

            $runtime = $application->getContainer()->get('temporal.runtime');

            return $runtime instanceof Runtime ? new TemporalRunner($runtime) : parent::getRunner($application);
        }

        return parent::getRunner($application);
    }
}
