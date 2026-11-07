<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use ReflectionClass;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final readonly class MigrationDiscovery
{
    public function __construct(private MigrationPaths $paths, private Logger $logger = new NullLogger())
    {
    }

    /** @return list<MigrationDescriptor> */
    public function discover(): array
    {
        $files = [];

        foreach ($this->paths->paths as $namespace => $directory) {
            $this->logger->debug('Discovering Temporal migrations', ['namespace' => $namespace, 'directory' => $directory]);

            if (!is_dir($directory) || !is_readable($directory)) {
                throw MigrationException::unreadableDirectory($directory);
            }

            $entries = scandir($directory);

            if ($entries === false) {
                throw MigrationException::cannotScanDirectory($directory);
            }

            foreach ($entries as $filename) {
                if (!str_ends_with($filename, '.php') || is_dir($directory . '/' . $filename)) {
                    continue;
                }

                if (preg_match('/^Migration([0-9]{14})\.php$/D', $filename, $matches) !== 1) {
                    throw MigrationException::invalidFilename($filename);
                }

                $id   = $matches[1];
                $date = DateTimeImmutable::createFromFormat('!YmdHis', $id, new DateTimeZone('UTC'));

                if ($date === false || $date->format('YmdHis') !== $id || isset($files[$id])) {
                    throw MigrationException::invalidIdentifier($id);
                }

                $path = realpath($directory . '/' . $filename);

                if ($path === false || !is_readable($path)) {
                    throw MigrationException::cannotReadFile($filename);
                }

                $files[$id] = [$path, $namespace . '\\' . substr($filename, 0, -4)];
            }
        }

        ksort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $id => [$path, $class]) {
            try {
                if (!class_exists($class, false)) {
                    (static function (string $file): void {
                        require_once $file;
                    })($path);
                }

                if (!class_exists($class, false)) {
                    throw MigrationException::classNotDeclared($class, $path);
                }

                $reflection  = new ReflectionClass($class);
                $constructor = $reflection->getConstructor();

                if (!is_a($class, Migration::class, true)
                    || !$reflection->isInstantiable()
                    || $reflection->getFileName() !== $path
                    || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)
                ) {
                    throw MigrationException::invalidMigrationClass($class);
                }

                $migrations[] = new MigrationDescriptor((string) $id, $path, $class);
            } catch (Throwable $exception) {
                $this->logger->error('Invalid Temporal migration', ['path' => $path, 'class' => $class]);
                throw MigrationException::cannotLoadMigration($path, $exception);
            }
        }

        return $migrations;
    }
}
