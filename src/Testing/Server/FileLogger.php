<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use DateTimeImmutable;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Appends test-environment log records to a file, one line per record.
 *
 * @internal
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        unset($context['exception']);

        $directory = dirname($this->file);

        // A fresh application has no var/log yet
        if (!is_dir($directory)) {
            @mkdir($directory, 0o777, true);
        }

        file_put_contents($this->file, sprintf(
            "[%s] %s: %s %s\n",
            (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            is_scalar($level) ? (string) $level : 'log',
            $message,
            json_encode($context, JSON_UNESCAPED_SLASHES),
        ), FILE_APPEND);
    }
}
