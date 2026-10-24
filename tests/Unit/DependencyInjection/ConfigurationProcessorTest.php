<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\DependencyInjection;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Worker\WorkflowPanicPolicy;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\apiKey;

use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;

#[CoversClass(Configuration::class)]
final class ConfigurationProcessorTest extends TestCase
{
    public function testImplicitDefaultClientsGetPrototypeDefaults(): void
    {
        $config = $this->process([]);

        foreach (['clients', 'scheduleClients'] as $key) {
            self::assertIsArray($config[$key]);
            assertSame(['default'], array_keys($config[$key]));

            $client = $config[$key]['default'];

            self::assertIsArray($client);
            assertSame('default', $client['namespace']);
            assertSame('%env(TEMPORAL_ADDRESS)%', $client['address']);
            assertSame('temporal.data_converter', $client['dataConverter']);
            assertSame([], $client['interceptors']);
            self::assertNull($client['apiKey']);

            self::assertIsArray($client['grpcContext']);
            assertArrayHasKey('retryOptions', $client['grpcContext']);
            assertArrayHasKey('metadata', $client['grpcContext']);
            assertArrayHasKey('options', $client['grpcContext']);
            assertSame(['value' => 5, 'format' => 'seconds'], $client['grpcContext']['timeout']);
        }
    }


    public function testExplicitClientsDisableImplicitDefault(): void
    {
        $config = $this->process([
            ['clients' => ['foo' => ['namespace' => 'foo']]],
            ['scheduleClients' => ['bar' => ['namespace' => 'bar']]],
        ]);

        self::assertIsArray($config['clients']);
        self::assertIsArray($config['scheduleClients']);
        assertSame(['foo'], array_keys($config['clients']));
        assertSame(['bar'], array_keys($config['scheduleClients']));
    }


    public function testExplicitClientInAnotherFileDisablesImplicitDefault(): void
    {
        $config = $this->process([
            ['defaultClient' => 'foo'],
            ['clients' => ['foo' => ['namespace' => 'foo']]],
        ]);

        self::assertIsArray($config['clients']);
        self::assertIsArray($config['scheduleClients']);
        assertSame(['foo'], array_keys($config['clients']));
        assertSame(['default'], array_keys($config['scheduleClients']));
    }


    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function backoffCoefficientKeyDataProvider(): iterable
    {
        yield 'camelCase' => ['backoffCoefficient'];
        yield 'deprecated snake_case' => ['backoff_coefficient'];
    }


    #[DataProvider('backoffCoefficientKeyDataProvider')]
    public function testBackoffCoefficient(string $key): void
    {
        $retryOptions = $this->processGrpcContext(['retryOptions' => [$key => 1.5]])['retryOptions'];

        self::assertIsArray($retryOptions);
        assertSame(1.5, $retryOptions['backoffCoefficient']);
        self::assertArrayNotHasKey('backoff_coefficient', $retryOptions);
    }


    public function testRetryOptionsDefaultToSdk(): void
    {
        $retryOptions = $this->processGrpcContext([])['retryOptions'];

        self::assertIsArray($retryOptions);
        self::assertNull($retryOptions['initialInterval']);
        self::assertNull($retryOptions['maximumInterval']);
        self::assertNull($retryOptions['backoffCoefficient']);
        self::assertNull($retryOptions['maximumAttempts']);
        self::assertNull($retryOptions['congestionInitialInterval']);
        self::assertNull($retryOptions['maximumJitterCoefficient']);
    }


    public function testMetadataValuesAreLists(): void
    {
        $metadata = $this->processGrpcContext(['metadata' => ['x-team' => 'billing', 'x-tags' => ['a', 'b']]])['metadata'];

        assertSame(['x-team' => ['billing'], 'x-tags' => ['a', 'b']], $metadata);
    }


    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidGrpcContextDataProvider(): iterable
    {
        yield 'jitter is 1' => [['retryOptions' => ['maximumJitterCoefficient' => 1.0]]];
        yield 'negative jitter' => [['retryOptions' => ['maximumJitterCoefficient' => -0.1]]];
        yield 'backoff below 1' => [['retryOptions' => ['backoffCoefficient' => 0.5]]];
        yield 'negative attempts' => [['retryOptions' => ['maximumAttempts' => -1]]];
        yield 'invalid congestion interval' => [['retryOptions' => ['congestionInitialInterval' => 'soon']]];
    }


    /**
     * @param array<string, mixed> $grpcContext
     */
    #[DataProvider('invalidGrpcContextDataProvider')]
    public function testInvalidGrpcContextIsRejected(array $grpcContext): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processGrpcContext($grpcContext);
    }


    /**
     * @return iterable<string, array{array<array-key, mixed>, non-empty-string}>
     */
    public static function invalidConfigurationDataProvider(): iterable
    {
        yield 'zero timeout' => [['clients' => ['default' => ['namespace' => 'default', 'grpcContext' => ['timeout' => ['value' => 0]]]]], 'Should be greater than or equal to 1'];
        yield 'empty identity' => [['clients' => ['default' => ['namespace' => 'default', 'identity' => '']]], 'cannot contain an empty value'];
        yield 'missing worker factory' => [['pool' => ['workerFactory' => 'App\\MissingFactory']], 'workerFactory class "App\\\\MissingFactory" not found'];
        yield 'invalid date interval' => [['workers' => ['default' => ['taskQueue' => 'default', 'workerStopTimeout' => 'soon']]], 'Failed parse date-interval'];
        yield 'exception interceptor without retryable errors' => [['workers' => ['default' => ['taskQueue' => 'default', 'exceptionInterceptor' => ['retryable' => []]]]], '"exceptionInterceptor" must be a service id'];
        yield 'exception interceptor with a non-throwable' => [['workers' => ['default' => ['taskQueue' => 'default', 'exceptionInterceptor' => ['retryableErrors' => ['stdClass']]]]], '"exceptionInterceptor" must be a service id'];
        yield 'cloud client without api key' => [['cloudClient' => ['enabled' => true]], '"cloudClient.apiKey" is required'];
        yield 'empty activity mocker' => [['pool' => ['testing' => ['activityMocker' => '']]], 'cannot contain an empty value'];
    }


    /**
     * @param array<array-key, mixed> $config
     */
    #[DataProvider('invalidConfigurationDataProvider')]
    public function testInvalidConfigurationIsRejected(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process([$config]);
    }


    public function testDateIntervalAcceptsEnvVariables(): void
    {
        $worker = $this->processWorker([
            'workerStopTimeout'        => '%env(WORKER_STOP_TIMEOUT)%',
            'deadlockDetectionTimeout' => 'env_0123456789abcdef_DEADLOCK_0123456789abcdef0123456789abcdef',
        ]);

        assertSame('%env(WORKER_STOP_TIMEOUT)%', $worker['workerStopTimeout']);
    }


    public function testActivityMockerAcceptsServiceReference(): void
    {
        $config = $this->process([['pool' => ['testing' => ['activityMocker' => '@app.activity_cache']]]]);

        self::assertIsArray($config['pool']);
        self::assertIsArray($config['pool']['testing']);
        assertSame('app.activity_cache', $config['pool']['testing']['activityMocker']);
    }


    /**
     * @return iterable<string, array{list<non-empty-string>, non-empty-string}>
     */
    public static function invalidConnectionsDataProvider(): iterable
    {
        yield 'repeated' => [['default', 'default'], 'Should not be repeated connection'];
        yield 'not found' => [['missing'], 'Not found connections: missing'];
        yield 'empty' => [[], 'Please set connection name.'];
    }


    /**
     * @param list<non-empty-string> $connections
     */
    #[DataProvider('invalidConnectionsDataProvider')]
    public function testLoggingDoctrineOpenTransactionConnections(array $connections, string $message): void
    {
        InstalledVersions::setHandler(static fn (): bool => true);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        try {
            (new Processor())->processConfiguration(new Configuration(['default'], ['default']), [
                ['pool' => ['useGlobalLoggingDoctrineOpenTransaction' => $connections]],
            ]);
        } finally {
            InstalledVersions::setHandler(null);
        }
    }


    public function testDeprecatedCertificateKeysMoveToTls(): void
    {
        $config = $this->process([['clients' => ['default' => [
            'namespace' => 'default',
            'clientKey' => 'key.pem',
            'clientPem' => 'cert.pem',
            'tls'       => ['serverName' => 'temporal.local'],
        ]]]]);

        self::assertIsArray($config['clients']);
        self::assertIsArray($config['clients']['default']);
        self::assertArrayNotHasKey('clientKey', $config['clients']['default']);
        self::assertEquals([
            'enabled'    => null,
            'rootCa'     => null,
            'clientKey'  => 'key.pem',
            'clientPem'  => 'cert.pem',
            'serverName' => 'temporal.local',
        ], $config['clients']['default']['tls']);
    }


    public function testClientKeyRequiresClientPem(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"tls.clientKey" and "tls.clientPem" must be set together');

        $this->process([['scheduleClients' => ['default' => ['namespace' => 'default', 'tls' => ['clientKey' => 'key.pem']]]]]);
    }


    public function testApiKeyServiceReference(): void
    {
        assertSame('key', (string) apiKey('key'));
        self::assertNull(apiKey(null));
        self::assertEquals(new Reference('app.api_key'), apiKey('@app.api_key'));
        assertSame('@', apiKey('@'));
    }


    public function testEnvConfigClientWithoutNamespace(): void
    {
        $config = $this->process([['clients' => ['default' => ['envConfig' => ['enabled' => true, 'profile' => 'prod']]]]]);

        self::assertIsArray($config['clients']);
        self::assertIsArray($config['clients']['default']);
        self::assertNull($config['clients']['default']['namespace']);
        assertSame(['enabled' => true, 'profile' => 'prod', 'configFile' => null], $config['clients']['default']['envConfig']);
    }


    public function testNamespaceIsRequiredWithoutEnvConfig(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"namespace" is required unless "envConfig.enabled" is true');

        $this->process([['clients' => ['default' => ['address' => 'localhost:7233']]]]);
    }


    public function testEnvConfigConflictsWithConnectionOptions(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Options "address", "apiKey" cannot be set together with "envConfig.enabled"');

        $this->process([['scheduleClients' => ['default' => ['envConfig' => ['enabled' => true], 'address' => 'localhost:7233', 'apiKey' => 'key']]]]);
    }


    public function testEnvConfigConflictsAcrossConfigFiles(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"apiKey" and "tls" cannot be set together with "envConfig.enabled"');

        $this->process([
            ['clients' => ['default' => ['namespace' => 'default', 'apiKey' => 'key']]],
            ['clients' => ['default' => ['envConfig' => ['enabled' => true]]]],
        ]);
    }


    public function testPoolHasDefaults(): void
    {
        $config = $this->process([]);

        self::assertIsArray($config['pool']);
        assertSame('temporal.data_converter', $config['pool']['dataConverter']);
        assertSame('%env(default:temporal.rr_rpc_default:RR_RPC)%', $config['pool']['roadrunnerRPC']);
    }


    public function testTaskQueueActivitiesPerSecondAcceptsFloat(): void
    {
        $worker = $this->processWorker(['taskQueueActivitiesPerSecond' => 0.5]);

        assertSame(0.5, $worker['taskQueueActivitiesPerSecond']);
    }


    /**
     * @param array<non-empty-string, mixed> $worker
     */
    #[DataProvider('invalidWorkerDataProvider')]
    public function testInvalidWorkerValueIsRejected(array $worker): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processWorker($worker);
    }


    /**
     * @return iterable<string, array{array<non-empty-string, mixed>}>
     */
    public static function invalidWorkerDataProvider(): iterable
    {
        yield 'negative concurrency' => [['maxConcurrentActivityExecutionSize' => -1]];
        yield 'string concurrency' => [['maxConcurrentActivityExecutionSize' => 'many']];
        yield 'negative task queue rate' => [['taskQueueActivitiesPerSecond' => -0.5]];
        yield 'unknown panic policy' => [['workflowPanicPolicy' => 'Panic']];
        yield 'versioning without version' => [['deploymentOptions' => ['useVersioning' => true]]];
        yield 'deployment name without build id' => [['deploymentOptions' => ['deploymentName' => 'billing']]];
        yield 'versioning with build id versioning' => [['deploymentOptions' => ['useVersioning' => true, 'deploymentName' => 'billing', 'buildId' => '1'], 'useBuildIDForVersioning' => true, 'buildID' => '1']];
        yield 'build id versioning with session worker' => [['useBuildIDForVersioning' => true, 'buildID' => '1', 'enableSessionWorker' => true]];
        yield 'unknown versioning behavior' => [['deploymentOptions' => ['defaultVersioningBehavior' => 'Latest']]];
        yield 'negative nexus pollers' => [['maxConcurrentNexusTaskPollers' => -1]];
    }


    /**
     * @return iterable<string, array{mixed, non-empty-string}>
     */
    public static function workflowPanicPolicyDataProvider(): iterable
    {
        yield 'default' => [null, 'BlockWorkflow'];
        yield 'string' => ['FailWorkflow', 'FailWorkflow'];
        yield 'enum' => [WorkflowPanicPolicy::FailWorkflow, 'FailWorkflow'];
    }


    #[DataProvider('workflowPanicPolicyDataProvider')]
    public function testWorkflowPanicPolicy(mixed $value, string $expected): void
    {
        $worker = $this->processWorker($value === null ? [] : ['workflowPanicPolicy' => $value]);

        assertSame($expected, $worker['workflowPanicPolicy']);
    }


    /**
     * @param array<string, mixed> $grpcContext
     *
     * @return array<array-key, mixed>
     */
    private function processGrpcContext(array $grpcContext): array
    {
        $config = $this->process([['clients' => ['default' => ['namespace' => 'default', 'grpcContext' => $grpcContext]]]]);

        self::assertIsArray($config['clients']);
        self::assertIsArray($config['clients']['default']);
        self::assertIsArray($config['clients']['default']['grpcContext']);

        return $config['clients']['default']['grpcContext'];
    }


    /**
     * @param array<non-empty-string, mixed> $worker
     *
     * @return array<array-key, mixed>
     */
    private function processWorker(array $worker): array
    {
        $config = $this->process([['workers' => ['default' => ['taskQueue' => 'default', ...$worker]]]]);

        self::assertIsArray($config['workers']);
        self::assertIsArray($config['workers']['default']);

        return $config['workers']['default'];
    }


    /**
     * @param array<array-key, mixed> $configs
     *
     * @return array<array-key, mixed>
     */
    private function process(array $configs): array
    {
        return (new Processor())->processConfiguration(
            new Configuration([], []),
            Configuration::withImplicitDefaultClients($configs),
        );
    }
}
