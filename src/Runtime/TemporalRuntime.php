<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use LogicException;
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

            if ($runtime instanceof Runtime) {
                return new TemporalRunner($runtime);
            }
        }

        dd($application);

        throw new LogicException('Is not a temporal runtime');
    }
}
