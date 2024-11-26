<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use Symfony\Component\Runtime\RunnerInterface as Runner;

final readonly class TemporalRunner implements Runner
{
    public function __construct(
        private Runtime $runtime
    ) {
    }


    public function run(): int
    {
        $this->runtime->run();

        return 0;
    }
}
