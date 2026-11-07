<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDescriptor;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDiscovery;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationGenerator;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationPaths;

#[CoversClass(MigrationDiscovery::class)]
#[CoversClass(MigrationGenerator::class)]
#[CoversClass(MigrationPaths::class)]
#[CoversClass(MigrationDescriptor::class)]
final class MigrationDiscoveryTest extends TestCase
{
    private string $directory;
    private string $namespace;

    protected function setUp(): void
    {
        $id              = bin2hex(random_bytes(6));
        $this->directory = sys_get_temp_dir() . '/temporal-migrations-' . $id;
        $this->namespace = 'MigrationFixture' . $id;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testGenerateInMissingDirectoryAndDiscoverWithoutComposer(): void
    {
        $paths = new MigrationPaths([$this->namespace => $this->directory], '/');
        $path  = (new MigrationGenerator($paths))->generate('Create billing');
        self::assertFileExists($path);
        $migrations = (new MigrationDiscovery($paths))->discover();
        self::assertCount(1, $migrations);
        self::assertSame(realpath($path), $migrations[0]->path);
        self::assertSame($migrations[0]->class, $migrations[0]->instantiate()::class);
        self::assertStringContainsString('up(Scheme $scheme)', (string) file_get_contents($path));
        self::assertStringNotContainsString('->delete()', (string) file_get_contents($path));
    }

    public function testGenerationDoesNotOverwrite(): void
    {
        $generator = new MigrationGenerator(new MigrationPaths([$this->namespace => $this->directory], '/'));
        mkdir($this->directory);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        for ($offset = 0; $offset < 3; ++$offset) {
            file_put_contents($this->directory . '/Migration' . $now->modify('+' . $offset . ' seconds')->format('YmdHis') . '.php', 'existing');
        }
        $this->expectException(MigrationException::class);
        $generator->generate('Second');
    }

    public function testMissingCatalogFails(): void
    {
        $this->expectException(MigrationException::class);
        (new MigrationDiscovery(new MigrationPaths([$this->namespace => $this->directory], '/')))->discover();
    }

    public function testInvalidDateFailsBeforeLoading(): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/Migration20260231000000.php', '<?php throw new RuntimeException("must not load");');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Invalid or duplicate');
        (new MigrationDiscovery(new MigrationPaths([$this->namespace => $this->directory], '/')))->discover();
    }

    public function testGeneratorRejectsDocblockInjection(): void
    {
        $this->expectException(MigrationException::class);
        (new MigrationGenerator(new MigrationPaths([$this->namespace => $this->directory], '/')))->generate('bad */ code');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDeclarations(): iterable
    {
        yield 'wrong class' => ['final class WrongClass {}'];
        yield 'wrong contract' => ['final class Migration20260101000000 {}'];
        yield 'abstract' => ['abstract class Migration20260101000000 implements Migration {}'];
        yield 'required constructor' => ['final class Migration20260101000000 implements Migration { public function __construct(string $required) {} public function up(Scheme $scheme): void {} public function down(Scheme $scheme): void {} }'];
    }

    #[DataProvider('invalidDeclarations')]
    public function testInvalidDeclarationsFailPreflight(string $declaration): void
    {
        mkdir($this->directory);
        file_put_contents($this->directory . '/Migration20260101000000.php', '<?php declare(strict_types=1); namespace ' . $this->namespace . '; use Vanta\\Integration\\Symfony\\Temporal\\Migration\\Migration; use Vanta\\Integration\\Symfony\\Temporal\\Migration\\Scheme; ' . $declaration);
        $this->expectException(MigrationException::class);
        (new MigrationDiscovery(new MigrationPaths([$this->namespace => $this->directory], '/')))->discover();
    }

    public function testDuplicateIdentifiersAcrossMappingsFailBeforeLoading(): void
    {
        mkdir($this->directory);
        $other = $this->directory . '-other';
        mkdir($other);

        try {
            foreach ([$this->directory, $other] as $directory) {
                file_put_contents($directory . '/Migration20260101000000.php', '<?php throw new \\LogicException("must not load");');
            }
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessage('duplicate migration identifier');
            (new MigrationDiscovery(new MigrationPaths([$this->namespace => $this->directory, $this->namespace . 'Other' => $other], '/')))->discover();
        } finally {
            unlink($other . '/Migration20260101000000.php');
            rmdir($other);
        }
    }

    public function testOverlappingNamespacesAreCaseInsensitiveLikePhp(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Overlapping migration namespace');

        new MigrationPaths(['AppMigrations' => 'first', 'appmigrations\\Billing' => 'second'], '/');
    }
}
