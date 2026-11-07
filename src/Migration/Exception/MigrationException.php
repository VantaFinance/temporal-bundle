<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration\Exception;

use RuntimeException;
use Throwable;

final class MigrationException extends RuntimeException
{
    public static function invalidMigrationName(): self
    {
        return new self('Migration name must start with a letter and contain only letters, digits, spaces, underscores or hyphens.');
    }

    public static function unknownPhpNamespace(string $namespace): self
    {
        return new self(sprintf('Unknown migration PHP namespace "%s".', $namespace));
    }

    public static function cannotCreateDirectory(string $directory): self
    {
        return new self(sprintf('Cannot create migration directory "%s".', $directory));
    }

    public static function cannotCreateFile(string $path): self
    {
        return new self(sprintf('Cannot exclusively create "%s"; check permissions or retry after a timestamp collision.', $path));
    }

    public static function incompleteFileWrite(string $path): self
    {
        return new self(sprintf('Incomplete write of migration "%s".', $path));
    }

    public static function unreadableDirectory(string $directory): self
    {
        return new self(sprintf('Migration directory "%s" is missing or unreadable.', $directory));
    }

    public static function cannotScanDirectory(string $directory): self
    {
        return new self(sprintf('Cannot scan migration directory "%s".', $directory));
    }

    public static function invalidFilename(string $filename): self
    {
        return new self(sprintf('Invalid migration filename "%s".', $filename));
    }

    public static function invalidIdentifier(string $id): self
    {
        return new self(sprintf('Invalid or duplicate migration identifier "%s".', $id));
    }

    public static function cannotReadFile(string $filename): self
    {
        return new self(sprintf('Cannot read migration "%s".', $filename));
    }

    public static function classNotDeclared(string $class, string $path): self
    {
        return new self(sprintf('Migration class "%s" is not declared in "%s".', $class, $path));
    }

    public static function invalidMigrationClass(string $class): self
    {
        return new self(sprintf('Migration "%s" must implement Migration, be declared by its file and have a public constructor without required arguments.', $class));
    }

    public static function cannotLoadMigration(string $path, Throwable $exception): self
    {
        return new self(sprintf('Cannot load migration "%s": %s', $path, $exception->getMessage()), 0, $exception);
    }

    public static function invalidPaths(): self
    {
        return new self('Migration paths require a valid PHP namespace and nonempty directory.');
    }

    public static function overlappingPhpNamespace(string $namespace): self
    {
        return new self(sprintf('Overlapping migration namespace "%s".', $namespace));
    }

    public static function missingPaths(): self
    {
        return new self('Configure at least one temporal.migrations.paths mapping.');
    }

    public static function invalidTimeout(): self
    {
        return new self('Migration timeout must be positive.');
    }

    public static function verificationFailed(string $description, int $attempts): self
    {
        return new self(sprintf('Unable to verify %s within %d observations and the migration deadline.', $description, $attempts));
    }

    public static function deadlineExceeded(): self
    {
        return new self('Temporal migration invocation deadline exceeded.');
    }

    public static function invalidDirection(): self
    {
        return new self('Migration direction must be up or down.');
    }

    public static function executionFailed(string $id, string $direction, string $path, int $completed, Throwable $exception): self
    {
        return new self(sprintf(
            'Temporal migration %s failed in %s (%s), after %d completed migrations. Partial effects may remain: %s',
            $id,
            $direction,
            $path,
            $completed,
            $exception->getMessage(),
        ), 0, $exception);
    }

    public static function unappliedSearchAttributes(string $namespace): self
    {
        return new self(sprintf('Search attributes for namespace "%s" have unapplied operations; call apply().', $namespace));
    }

    public static function invalidSearchAttributeName(string $name): self
    {
        return new self(sprintf('Invalid or reserved search attribute name "%s".', $name));
    }

    public static function contradictorySearchAttributeOperations(string $name): self
    {
        return new self(sprintf('Contradictory operations for search attribute "%s".', $name));
    }

    public static function systemSearchAttribute(string $name): self
    {
        return new self(sprintf('Cannot mutate system search attribute "%s".', $name));
    }

    public static function searchAttributeTypeConflict(string $name, int $existing, int $type): self
    {
        return new self(sprintf('Search attribute "%s" has type %d, requested %d; use a new name for a new type.', $name, $existing, $type));
    }

    public static function invalidSearchAttributeType(): self
    {
        return new self('Temporal returned an invalid search attribute type.');
    }

    public static function emptyNamespaceDescription(): self
    {
        return new self('Namespace description cannot be empty; Temporal ignores empty updates.');
    }

    public static function emptyNamespaceOwnerEmail(): self
    {
        return new self('Namespace owner email cannot be empty; Temporal ignores empty updates.');
    }

    public static function invalidRetention(): self
    {
        return new self('Namespace retention must be a positive fixed interval without years, months or fractional seconds.');
    }

    public static function namespaceNotRegistered(string $namespace): self
    {
        return new self(sprintf('Namespace "%s" is not in the registered state; refusing to recreate or reactivate it.', $namespace));
    }

    public static function cannotDeleteWithPendingSettings(): self
    {
        return new self('Cannot delete a namespace with pending namespace settings; use a fresh namespace builder.');
    }

    public static function unappliedNamespaceSettings(string $namespace): self
    {
        return new self(sprintf('Namespace "%s" has unapplied namespace settings; call ensure().', $namespace));
    }

    public static function retentionRequired(string $namespace): self
    {
        return new self(sprintf('Creating namespace "%s" requires explicit retention().', $namespace));
    }

    public static function invalidNamespace(): self
    {
        return new self('Temporal namespace must not be empty or contain null bytes.');
    }
}
