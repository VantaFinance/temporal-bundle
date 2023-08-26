<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal;

use Spiral\RoadRunner\Environment as RoadRunnerEnvironment;

final class Environment
{
    /**
     * @param array<non-empty-string, non-empty-string> $with
     */
    public static function create(array $with): RoadRunnerEnvironment
    {
        return new RoadRunnerEnvironment([...$_ENV, ...$_SERVER, ...$with]);
    }
}
