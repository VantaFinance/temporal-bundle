<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server;

use InvalidArgumentException;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringEndsWith;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Temporal\Common\SearchAttributes\ValueType;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\CollectingLogger;
use Vanta\Integration\Symfony\Temporal\Test\Unit\Testing\Server\Fixture\Fixture;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalServerType;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironmentFactory;

#[CoversClass(TemporalTestEnvironmentFactory::class)]
final class TemporalTestEnvironmentFactoryTest extends TestCase
{
    private string $directory;
    private int $port;

    protected function setUp(): void
    {
        $this->directory = Fixture::temporaryDirectory();
        $this->port      = Fixture::freePort();

        file_put_contents($this->directory . '/rr.yaml', "version: '3'\n");
    }

    protected function tearDown(): void
    {
        Fixture::removeDirectory($this->directory);

        @unlink(sprintf('%s/fake-temporal-%d.ready', sys_get_temp_dir(), $this->port));
        @unlink(sprintf('%s/fake-temporal-%d.args', sys_get_temp_dir(), $this->port));
    }

    public function testBuildsDevServerAndRoadRunner(): void
    {
        $logger      = new CollectingLogger();
        $environment = TemporalTestEnvironmentFactory::fromAttribute($this->attribute(TemporalServerType::Dev), $this->directory, $logger);

        $environment->start();

        assertTrue($environment->isRunning());
        assertSame(1, $logger->count('info', 'Starting Temporal dev server'));
        assertSame(1, $logger->count('info', 'Starting RoadRunner'));

        $environment->stop();

        assertFalse($environment->isRunning());
    }

    public function testExternalStartsOnlyRoadRunner(): void
    {
        $logger      = new CollectingLogger();
        $environment = TemporalTestEnvironmentFactory::fromAttribute($this->attribute(TemporalServerType::External), $this->directory, $logger);

        $environment->start();

        assertSame(0, $logger->count('info', 'Starting Temporal dev server'));
        assertSame(1, $logger->count('info', 'Starting RoadRunner'));

        $environment->stop();
    }

    public function testPassesSearchAttributesToDevServer(): void
    {
        $environment = TemporalTestEnvironmentFactory::fromAttribute(new WithTemporalEnvironment(
            temporalAddress: '127.0.0.1:' . $this->port,
            temporalBinary: Fixture::binary('fake-temporal'),
            searchAttributes: ['CustomerId' => ValueType::Keyword, 'Amount' => ValueType::Float],
            roadRunnerConfig: 'rr.yaml',
            roadRunnerBinary: Fixture::binary('fake-rr'),
            startTimeout: 5.0,
        ), $this->directory);

        $environment->start();

        $arguments = (string) file_get_contents(sprintf('%s/fake-temporal-%d.args', sys_get_temp_dir(), $this->port));

        $environment->stop();

        assertStringContainsString('"--search-attribute","CustomerId=Keyword"', $arguments);
        assertStringContainsString('"--search-attribute","Amount=Double"', $arguments);
    }

    /**
     * @return iterable<string, array{TemporalServerType}>
     */
    public static function serversWithoutSearchAttributes(): iterable
    {
        yield 'time skipping' => [TemporalServerType::TimeSkipping];
        yield 'external' => [TemporalServerType::External];
    }

    #[DataProvider('serversWithoutSearchAttributes')]
    public function testSearchAttributesRequireDevServer(TemporalServerType $temporal): void
    {
        $this->expectException(InvalidArgumentException::class);

        TemporalTestEnvironmentFactory::fromAttribute(new WithTemporalEnvironment(
            temporal: $temporal,
            searchAttributes: ['CustomerId' => ValueType::Keyword],
        ), $this->directory);
    }

    public function testPassesTemporalStartOptionsToDevServer(): void
    {
        $environment = TemporalTestEnvironmentFactory::fromAttribute(new WithTemporalEnvironment(
            temporalAddress: '127.0.0.1:' . $this->port,
            temporalBinary: Fixture::binary('fake-temporal'),
            temporalNamespace: 'billing',
            temporalDynamicConfig: ['frontend.enableUpdateWorkflowExecution' => true, 'limit.maxIDLength' => 300],
            temporalArguments: ['--log-level', 'warn'],
            roadRunnerConfig: 'rr.yaml',
            roadRunnerBinary: Fixture::binary('fake-rr'),
            startTimeout: 5.0,
        ), $this->directory);

        $environment->start();

        $arguments = (string) file_get_contents(sprintf('%s/fake-temporal-%d.args', sys_get_temp_dir(), $this->port));

        $environment->stop();

        assertStringContainsString('"--namespace","billing"', $arguments);
        assertStringContainsString('"--dynamic-config-value","frontend.enableUpdateWorkflowExecution=true"', $arguments);
        assertStringContainsString('"--dynamic-config-value","limit.maxIDLength=300"', $arguments);
        // extra arguments come last, so they override the built-in "--log-level error"
        assertStringEndsWith('"--log-level","warn"]', $arguments);
    }

    /**
     * @return iterable<string, array{WithTemporalEnvironment}>
     */
    public static function devOnlyOptionsOnOtherServers(): iterable
    {
        foreach ([TemporalServerType::TimeSkipping, TemporalServerType::External] as $temporal) {
            yield $temporal->value . ' namespace' => [new WithTemporalEnvironment(temporal: $temporal, temporalNamespace: 'billing')];
            yield $temporal->value . ' dynamic config' => [new WithTemporalEnvironment(temporal: $temporal, temporalDynamicConfig: ['a.b' => true])];
            yield $temporal->value . ' arguments' => [new WithTemporalEnvironment(temporal: $temporal, temporalArguments: ['--log-level', 'warn'])];
        }
    }

    #[DataProvider('devOnlyOptionsOnOtherServers')]
    public function testTemporalStartOptionsRequireDevServer(WithTemporalEnvironment $attribute): void
    {
        $this->expectException(InvalidArgumentException::class);

        TemporalTestEnvironmentFactory::fromAttribute($attribute, $this->directory);
    }

    public function testBareBinaryNamesAreLookedUpInProjectDirectory(): void
    {
        // PATH wins over the project directory: real binaries there would be started instead of the fakes
        if ((new ExecutableFinder())->find('temporal') != null || (new ExecutableFinder())->find('rr') != null) {
            self::markTestSkipped('Real temporal/rr binaries are in PATH');
        }

        symlink(Fixture::binary('fake-temporal'), $this->directory . '/temporal');
        symlink(Fixture::binary('fake-rr'), $this->directory . '/rr');

        $environment = TemporalTestEnvironmentFactory::fromAttribute(new WithTemporalEnvironment(
            temporalAddress: '127.0.0.1:' . $this->port,
            roadRunnerConfig: 'rr.yaml',
            startTimeout: 5.0,
        ), $this->directory);

        $environment->start();

        assertTrue($environment->isRunning());

        $environment->stop();
    }

    private function attribute(TemporalServerType $temporal): WithTemporalEnvironment
    {
        return new WithTemporalEnvironment(
            temporal: $temporal,
            temporalAddress: '127.0.0.1:' . $this->port,
            temporalBinary: Fixture::binary('fake-temporal'),
            roadRunnerConfig: 'rr.yaml',
            roadRunnerBinary: Fixture::binary('fake-rr'),
            startTimeout: 5.0,
        );
    }
}
