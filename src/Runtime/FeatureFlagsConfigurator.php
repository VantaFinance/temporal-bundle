<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Runtime;

use Temporal\Worker\FeatureFlags;

/**
 * @internal
 */
final readonly class FeatureFlagsConfigurator
{
    /**
     * @param array<non-empty-string, bool> $flags
     */
    public function __construct(
        private array $flags,
    ) {
    }


    public function __invoke(object $workerFactory): void
    {
        foreach ($this->flags as $flag => $value) {
            FeatureFlags::${$flag} = $value;
        }
    }
}
