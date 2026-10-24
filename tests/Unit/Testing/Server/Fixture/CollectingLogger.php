<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture;

use Psr\Log\AbstractLogger;
use Stringable;

final class CollectingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string}>
     */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message]; // @phpstan-ignore cast.string
    }

    public function count(string $level, string $message): int
    {
        return count(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] == $level && $record['message'] == $message,
        ));
    }

    public function hasLevel(string $level): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] == $level) {
                return true;
            }
        }

        return false;
    }
}
