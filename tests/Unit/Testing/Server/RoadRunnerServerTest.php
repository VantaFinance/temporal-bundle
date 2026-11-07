<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertFileDoesNotExist;
use function PHPUnit\Framework\assertFileExists;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\CollectingLogger;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\Fixture;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ProcessServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\RoadRunnerServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\ServerStartFailed;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

#[CoversClass(RoadRunnerServer::class)]
#[CoversClass(ProcessServer::class)]
#[CoversClass(ServerStartFailed::class)]
#[CoversClass(TemporalTestEnvironment::class)]
#[RequiresPhpExtension('posix')]
final class RoadRunnerServerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = Fixture::temporaryDirectory();
    }

    protected function tearDown(): void
    {
        Fixture::removeDirectory($this->directory);
    }

    public function testStartAndStop(): void
    {
        $server = $this->server($this->config('rr.yaml'));

        $server->start();

        assertTrue($server->isRunning());

        $server->stop();

        assertFalse($server->isRunning());

        $server->stop();

        assertFalse($server->isRunning());
    }

    public function testStartTwiceDoesNotSpawnSecondProcess(): void
    {
        $config = $this->config('rr.yaml');
        $logger = new CollectingLogger();
        $server = $this->server($config, $logger);

        $server->start();

        $pid = Fixture::readPid($config . '.pid');

        $server->start();

        assertSame($pid, Fixture::readPid($config . '.pid'));
        assertSame(1, $logger->count('info', 'Starting RoadRunner'));
        assertSame(1, $logger->count('debug', 'RoadRunner is already running'));

        $server->stop();
    }

    public function testMissingBinary(): void
    {
        $server = new RoadRunnerServer(binary: $this->directory . '/missing-rr', configFile: $this->config('rr.yaml'));

        $this->expectException(ServerStartFailed::class);
        $this->expectExceptionMessage('missing-rr');

        $server->start();
    }

    public function testMissingConfig(): void
    {
        $server = $this->server($this->directory . '/missing.yaml');

        $this->expectException(ServerStartFailed::class);
        $this->expectExceptionMessage('missing.yaml');

        $server->start();
    }

    public function testExitDuringStartupReportsLogTail(): void
    {
        $logger = new CollectingLogger();
        $server = $this->server($this->config('fail-exit.yaml'), $logger);

        try {
            $server->start();
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed $e) {
            assertStringContainsString('fake-rr', $e->getMessage());
            assertStringContainsString($this->directory . '/rr.log', $e->getMessage());
            assertStringContainsString('boom on startup', $e->getMessage());
        }

        assertFalse($server->isRunning());
        assertTrue($logger->hasLevel('error'));
    }

    public function testNeverReadyTimesOutAndKillsProcess(): void
    {
        $config = $this->config('never-ready.yaml');
        $server = $this->server($config, startTimeout: 0.5);

        try {
            $server->start();
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed $e) {
            assertStringContainsString('did not become ready within 0.5 seconds', $e->getMessage());
        }

        assertFalse($server->isRunning());
        assertTrue(Fixture::waitUntilGone(Fixture::readPid($config . '.pid')));
    }

    public function testRelativeConfigWithCustomWorkingDirectory(): void
    {
        $this->config('rr.yaml');

        $workingDirectory = $this->directory . '/work';

        mkdir($workingDirectory);

        $cwd = (string) getcwd();

        chdir($this->directory);

        try {
            $server = new RoadRunnerServer(
                binary: Fixture::binary('fake-rr'),
                configFile: 'rr.yaml',
                workingDirectory: $workingDirectory,
                logFile: $this->directory . '/rr.log',
                startTimeout: 5.0,
            );

            $server->start();
        } finally {
            chdir($cwd);
        }

        assertTrue($server->isRunning());

        $server->stop();
    }

    public function testRestartAfterStop(): void
    {
        $logger = new CollectingLogger();
        $server = $this->server($this->config('rr.yaml'), $logger);

        $server->start();
        $server->stop();
        $server->start();
        $server->stop();

        assertSame(2, $logger->count('info', 'Starting RoadRunner'));
    }

    public function testShutdownHookStopsServerWithoutExplicitStop(): void
    {
        $config  = $this->config('rr.yaml');
        $process = new Process([
            (string) (new PhpExecutableFinder())->find(false),
            Fixture::binary('start-and-exit.php'),
            Fixture::binary('fake-rr'),
            $config,
        ]);

        $process->mustRun();

        assertStringContainsString('started', $process->getOutput());
        assertFileExists($config . '.pid');
        assertTrue(Fixture::waitUntilGone(Fixture::readPid($config . '.pid')), 'fake-rr must be stopped by the shutdown hook');
    }

    public function testFailsWhenAnotherProcessAlreadyServes(): void
    {
        $config = $this->config('rr.yaml');

        // The fake reports "ready" while the pid in the marker is alive: this test process plays the foreign server
        file_put_contents($config . '.ready', (string) getmypid());

        try {
            $this->server($config)->start();
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed $e) {
            assertStringContainsString('already answering', $e->getMessage());
        }

        assertFileDoesNotExist($config . '.pid');
    }

    public function testResolvesBinaryFromPath(): void
    {
        $path = (string) getenv('PATH');

        putenv('PATH=' . dirname(Fixture::binary('fake-rr')) . PATH_SEPARATOR . $path);
        $_SERVER['PATH'] = $_ENV['PATH'] = (string) getenv('PATH');

        try {
            $server = new RoadRunnerServer(
                binary: 'fake-rr',
                configFile: $this->config('rr.yaml'),
                logFile: $this->directory . '/rr.log',
                startTimeout: 5.0,
            );

            $server->start();

            assertTrue($server->isRunning());

            $server->stop();
        } finally {
            putenv('PATH=' . $path);
            $_SERVER['PATH'] = $_ENV['PATH'] = $path;
        }
    }

    public function testResolvesBinaryFromBinaryDirectories(): void
    {
        $server = new RoadRunnerServer(
            configFile: $this->config('rr.yaml'),
            binary: 'fake-rr',
            logFile: $this->directory . '/rr.log',
            startTimeout: 5.0,
            binaryDirectories: [dirname(Fixture::binary('fake-rr'))],
        );

        $server->start();

        assertTrue($server->isRunning());

        $server->stop();
    }

    public function testUnknownBinaryName(): void
    {
        $server = new RoadRunnerServer(
            configFile: $this->config('rr.yaml'),
            binary: 'definitely-not-an-rr-binary',
            binaryDirectories: [$this->directory],
        );

        $this->expectException(ServerStartFailed::class);
        $this->expectExceptionMessage('definitely-not-an-rr-binary');

        $server->start();
    }

    public function testLogTailContainsOnlyCurrentRun(): void
    {
        file_put_contents($this->directory . '/rr.log', "OLD RUN\n");

        try {
            $this->server($this->config('fail-exit.yaml'))->start();
            self::fail('ServerStartFailed expected');
        } catch (ServerStartFailed $e) {
            assertStringContainsString('boom on startup', $e->getMessage());
            assertStringNotContainsString('OLD RUN', $e->getMessage());
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testEnvironmentStopsServersInParallel(): void
    {
        $servers = [];

        foreach (['a', 'b'] as $name) {
            mkdir($this->directory . '/' . $name);

            $config = $this->directory . '/' . $name . '/slow-stop.yaml';

            file_put_contents($config, "version: '3'\n");

            $servers[] = new RoadRunnerServer(
                configFile: $config,
                binary: Fixture::binary('fake-rr'),
                logFile: $this->directory . '/' . $name . '/rr.log',
                startTimeout: 5.0,
            );
        }

        $environment = new TemporalTestEnvironment(...$servers);
        $environment->start();

        $startedAt = microtime(true);
        $environment->stop();
        $elapsed = microtime(true) - $startedAt;

        assertFalse($environment->isRunning());
        assertTrue($elapsed < 1.6, sprintf('Two servers that each need ~1s to stop took %.2fs: they were stopped one after another', $elapsed));
    }

    public function testShutdownHookIsRegisteredOncePerProcess(): void
    {
        $first  = $this->server($this->config('rr.yaml'));
        $logger = new CollectingLogger();

        mkdir($this->directory . '/second');
        file_put_contents($this->directory . '/second/rr.yaml', "version: '3'\n");

        $second = new RoadRunnerServer(
            configFile: $this->directory . '/second/rr.yaml',
            binary: Fixture::binary('fake-rr'),
            logFile: $this->directory . '/second/rr.log',
            startTimeout: 5.0,
            logger: $logger,
        );

        $first->start();
        $second->start();
        $second->stop();
        $first->stop();

        // Starting a server per test must not pile up one shutdown function per instance
        assertSame(0, $logger->count('debug', 'Registered shutdown hook for RoadRunner'));
    }

    public function testDefaultLogFileIsRemovedAfterStop(): void
    {
        $server = new RoadRunnerServer(
            configFile: $this->config('rr.yaml'),
            binary: Fixture::binary('fake-rr'),
            startTimeout: 5.0,
        );

        $server->start();

        assertFileExists(ProcessServer::defaultLogFile('rr'));

        $server->stop();

        assertFileDoesNotExist(ProcessServer::defaultLogFile('rr'));
    }

    public function testArgumentsAreNotTreatedAsProcessPlaceholders(): void
    {
        $server = new RoadRunnerServer(
            configFile: $this->config('rr.yaml'),
            binary: Fixture::binary('fake-rr'),
            overrides: ['server.env.TOKEN' => '"${:TOKEN}"'],
            logFile: $this->directory . '/rr.log',
            startTimeout: 5.0,
        );

        $server->start();

        assertTrue($server->isRunning());

        $server->stop();
    }

    private function config(string $name): string
    {
        $config = $this->directory . '/' . $name;

        file_put_contents($config, "version: '3'\n");

        return $config;
    }

    private function server(string $config, ?CollectingLogger $logger = null, float $startTimeout = 5.0): RoadRunnerServer
    {
        return new RoadRunnerServer(
            binary: Fixture::binary('fake-rr'),
            configFile: $config,
            logFile: $this->directory . '/rr.log',
            startTimeout: $startTimeout,
            logger: $logger ?? new CollectingLogger(),
        );
    }
}
