<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

/** @internal */
final readonly class MigrationPaths
{
    /** @var non-empty-array<non-empty-string, non-empty-string> */
    public array $paths;

    /** @param array<string, string> $paths */
    public function __construct(array $paths, string $projectDirectory)
    {
        $resolved = [];

        foreach ($paths as $namespace => $path) {
            $namespace = trim($namespace, '\\');

            if ($namespace === ''
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $namespace) !== 1
                || trim($path) === ''
                || str_contains($path, "\0")
            ) {
                throw MigrationException::invalidPaths();
            }

            $candidate = strtolower($namespace) . '\\';

            foreach (array_keys($resolved) as $existing) {
                $existing = strtolower($existing) . '\\';

                if (str_starts_with($candidate, $existing) || str_starts_with($existing, $candidate)) {
                    throw MigrationException::overlappingPhpNamespace($namespace);
                }
            }

            $resolved[$namespace] = str_starts_with($path, '/') ? $path : $projectDirectory . '/' . $path;
        }

        if ($resolved === []) {
            throw MigrationException::missingPaths();
        }

        $this->paths = $resolved;
    }
}
