<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use LogicException;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDiscovery;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationGenerator;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationPaths;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationGenerateCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationMigrateCommand;

#[CoversClass(MigrationGenerateCommand::class)]
#[CoversClass(MigrationMigrateCommand::class)]
final class MigrationCommandTest extends TestCase
{
    public function testGenerationAndCancellationDoNotConstructClients(): void
    {
        $directory = sys_get_temp_dir() . '/migration-command-' . bin2hex(random_bytes(5));
        $paths     = new MigrationPaths(['CommandFixture' . bin2hex(random_bytes(5)) => $directory], '/');

        try {
            $generate = new CommandTester(new MigrationGenerateCommand(new MigrationGenerator($paths)));

            assertSame(0, $generate->execute(['name' => 'Create namespace']));

            $migrate = new CommandTester(new MigrationMigrateCommand(new MigrationDiscovery($paths), static function (): MigrationRunner {
                throw new LogicException('Clients must remain unconstructed.');
            }, 'test', 'billing'));

            $migrate->setInputs(['no']);

            assertSame(0, $migrate->execute([], ['interactive' => true]));
            assertStringContainsString('Cancelled', $migrate->getDisplay());
            assertSame(2, $migrate->execute(['direction' => 'invalid']));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
