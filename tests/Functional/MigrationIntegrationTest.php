<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\MigrationCompilerPass;
use Vanta\Integration\Symfony\Temporal\Test\App\MigrationKernel;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationGenerateCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\MigrationMigrateCommand;

#[CoversClass(MigrationCompilerPass::class)]
#[CoversClass(MigrationGenerateCommand::class)]
#[CoversClass(MigrationMigrateCommand::class)]
final class MigrationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists('Temporal\\Client\\GRPC\\OperatorClientInterface')) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
    }

    public function testWarmProductionContainerDiscoversNewFilesWithoutServer(): void
    {
        $directory = sys_get_temp_dir() . '/migration-' . bin2hex(random_bytes(5));
        $kernel    = new MigrationKernel($directory, '127.0.0.1:1', 'default');

        try {
            $application = new Application($kernel);
            $generate    = new CommandTester($application->find('temporal:migrations:generate'));

            assertSame(0, $generate->execute(['name' => 'Initial']));

            $migrate = new CommandTester($application->find('temporal:migrations:migrate'));

            $migrate->setInputs(['no']);

            assertSame(0, $migrate->execute([], ['interactive' => true]));
            assertStringContainsString('Cancelled', $migrate->getDisplay());

            $kernel->shutdown();

            $namespace = 'RuntimeMigrations' . str_replace('-', '', basename($directory));
            $file      = $directory . '/migrations/Migration20990101000000.php';

            file_put_contents($file, '<?php declare(strict_types=1); namespace ' . $namespace . '; final class Migration20990101000000 implements \\Vanta\\Integration\\Symfony\\Temporal\\Migration\\Migration { public function up(\\Vanta\\Integration\\Symfony\\Temporal\\Migration\\Scheme $scheme): void {} public function down(\\Vanta\\Integration\\Symfony\\Temporal\\Migration\\Scheme $scheme): void {} }');

            $kernel      = new MigrationKernel($directory, '127.0.0.1:1', 'default');
            $application = new Application($kernel);
            $migrate     = new CommandTester($application->find('temporal:migrations:migrate'));

            $migrate->setInputs(['no']);

            assertSame(0, $migrate->execute([], ['interactive' => true]));
            assertStringContainsString('20990101000000', $migrate->getDisplay());
        } finally {
            $kernel->shutdown();

            (new Filesystem())->remove($directory);
        }
    }
}
