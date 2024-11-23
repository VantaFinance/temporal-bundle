<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal;

use Spiral\RoadRunner\Environment as RoadRunnerEnvironment;

/**
 * @phpstan-import-type EnvironmentVariables from RoadRunnerEnvironment
 */
final class Environment
{
    /**
     * @param EnvironmentVariables $with
     */
    public static function create(array $with): RoadRunnerEnvironment
    {
        /**@phpstan-ignore-next-line */
        return new RoadRunnerEnvironment([...$_ENV, ...$_SERVER, ...$with]);
    }
}
