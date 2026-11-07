<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsPlugin
{
    /**
     * @param list<non-empty-string> $clients
     * @param list<non-empty-string> $scheduleClients
     */
    public function __construct(
        public array $clients = [],
        public array $scheduleClients = [],
        public bool $workers = false,
    ) {
    }
}
