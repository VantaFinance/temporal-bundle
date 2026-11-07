<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional;

use Composer\InstalledVersions;
use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ClientDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\ScheduleClientDebugCommand;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkerDebugCommand;

#[RunTestsInSeparateProcesses]
#[CoversClass(ClientDebugCommand::class)]
#[CoversClass(ScheduleClientDebugCommand::class)]
#[CoversClass(WorkerDebugCommand::class)]
final class DebugCommandTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, string> $options
     */
    protected static function createKernel(array $options = []): Kernel
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(TemporalBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }


    public function testClientsShowConnectionWithoutSecrets(): void
    {
        $_SERVER['TEMPORAL_CLIENT_KEY_PATH']  = '/secret/client.key';
        $_SERVER['TEMPORAL_CLIENT_CERT_PATH'] = '/secret/client.pem';

        $display = $this->commandDisplay('temporal.yaml', 'debug:temporal:clients', ['clients' => ['cloud', 'default']]);

        assertStringContainsString('TLS: auto, client certificate, API key: none', $display);
        assertStringContainsString('TLS: auto, API key: none', $display);
        assertStringNotContainsString('/secret/', $display);

        $display = $this->commandDisplay('temporal.yaml', 'debug:temporal:schedule-clients', ['clients' => ['cloud']]);

        assertStringContainsString('client certificate', $display);
        assertStringNotContainsString('/secret/', $display);
    }


    public function testEnvConfigClient(): void
    {
        if (!class_exists('Temporal\Common\EnvConfig\ConfigClient')) {
            self::markTestSkipped('Environment configuration requires temporal/sdk >= 2.17');
        }

        $display = $this->commandDisplay('temporal_with_env_config.yaml', 'debug:temporal:clients');

        assertStringContainsString('envConfig: profile prod, TOML configured', $display);
    }


    public function testWorkersShowFeatureFlags(): void
    {
        $display = $this->commandDisplay('temporal_with_feature_flags.yaml', 'debug:temporal:workers');

        assertStringContainsString('warnOnWorkflowUnfinishedHandlers: false', $display);
        assertStringContainsString('workflowDeferredHandlerStart: true', $display);
        assertStringContainsString('Plugins', $display);
        assertStringContainsString('temporal/sdk', $display);
        assertStringContainsString((string) InstalledVersions::getPrettyVersion('temporal/sdk'), $display);
        assertStringContainsString(InstalledVersions::isInstalled('roadrunner/worker') ? 'roadrunner/* (RoadRunner 3)' : 'spiral/* (RoadRunner 2025)', $display);
    }


    /**
     * @param non-empty-string     $configFile
     * @param non-empty-string     $command
     * @param array<string, mixed> $input
     */
    private function commandDisplay(string $configFile, string $command, array $input = []): string
    {
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel) use ($configFile): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/' . $configFile);
        }]);

        $tester = new CommandTester((new Application($kernel))->find($command));
        $tester->execute($input);

        return $tester->getDisplay();
    }
}
