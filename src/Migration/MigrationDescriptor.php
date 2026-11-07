<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

/** @internal */
final readonly class MigrationDescriptor
{
    /** @param class-string<Migration> $class */
    public function __construct(public string $id, public string $path, public string $class)
    {
    }

    public function instantiate(): Migration
    {
        return new ($this->class)();
    }
}
