<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use Symfony\Component\DependencyInjection\Definition;

/**
 * @param class-string|null                  $class
 * @param array<int|non-empty-string,mixed>  $arguments
 */
function definition(?string $class = null, array $arguments = []): Definition
{
    return new Definition($class, $arguments);
}
