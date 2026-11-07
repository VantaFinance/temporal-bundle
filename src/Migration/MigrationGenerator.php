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
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;

final readonly class MigrationGenerator
{
    public function __construct(private MigrationPaths $paths, private Logger $logger = new NullLogger())
    {
    }

    public function generate(string $name, ?string $namespace = null): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9 _-]*$/D', $name) !== 1) {
            throw MigrationException::invalidMigrationName();
        }

        $namespace ??= array_key_first($this->paths->paths);
        $directory = $this->paths->paths[$namespace] ?? null;

        if ($directory === null) {
            throw MigrationException::unknownPhpNamespace($namespace);
        }

        $this->logger->debug('Generating Temporal migration', ['namespace' => $namespace, 'directory' => $directory]);

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw MigrationException::cannotCreateDirectory($directory);
        }

        $class  = 'Migration' . (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('YmdHis');
        $path   = $directory . '/' . $class . '.php';
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            $this->logger->error('[FIX] Cannot create Temporal migration file', ['path' => $path]);
            throw MigrationException::cannotCreateFile($path);
        }

        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use Vanta\Integration\Symfony\Temporal\Migration\Migration;
            use Vanta\Integration\Symfony\Temporal\Migration\Scheme;

            /** {$name} */
            final class {$class} implements Migration
            {
                public function up(Scheme \$scheme): void
                {
                }

                public function down(Scheme \$scheme): void
                {
                }
            }

            PHP;

        try {
            $written = fwrite($handle, $contents);

            if ($written !== strlen($contents)) {
                @unlink($path);
                throw MigrationException::incompleteFileWrite($path);
            }
        } finally {
            fclose($handle);
        }

        $this->logger->info('Created Temporal migration', ['path' => $path]);

        return $path;
    }
}
