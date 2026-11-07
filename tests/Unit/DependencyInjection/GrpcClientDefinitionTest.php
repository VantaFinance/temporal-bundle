<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\DependencyInjection;

use Carbon\CarbonInterval;
use DomainException;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ServiceClient;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcContext;

#[CoversFunction('Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcClient')]
#[CoversFunction('Vanta\Integration\Symfony\Temporal\DependencyInjection\grpcContext')]
final class GrpcClientDefinitionTest extends TestCase
{
    private const RETRY_OPTIONS = [
        'initialInterval'           => null,
        'maximumInterval'           => null,
        'backoffCoefficient'        => null,
        'maximumAttempts'           => null,
        'nonRetryableExceptions'    => [],
        'congestionInitialInterval' => null,
        'maximumJitterCoefficient'  => null,
    ];


    /**
     * @return iterable<string, array{array<string, mixed>, ?string, non-empty-string}>
     */
    public static function tlsDataProvider(): iterable
    {
        yield 'nothing' => [[], null, 'create'];
        yield 'api key' => [[], '%env(TEMPORAL_API_KEY)%', 'createSSL'];
        yield 'api key, tls disabled' => [['enabled' => false], 'key', 'create'];
        yield 'tls enabled' => [['enabled' => true], null, 'createSSL'];
        yield 'root ca' => [['rootCa' => 'ca.pem'], null, 'createSSL'];
        yield 'client certificate' => [['clientKey' => 'key.pem', 'clientPem' => 'cert.pem'], null, 'createSSL'];
        yield 'certificates, tls disabled' => [['enabled' => false, 'clientKey' => 'key.pem', 'clientPem' => 'cert.pem'], null, 'create'];
    }


    /**
     * @param array<string, mixed> $tls
     * @param non-empty-string     $method
     */
    #[DataProvider('tlsDataProvider')]
    public function testTls(array $tls, ?string $apiKey, string $method): void
    {
        $definition = grpcClient(self::client($tls, $apiKey), 'temporal.clients.default'); // @phpstan-ignore argument.type

        assertEquals([ServiceClient::class, $method], $definition->getFactory());
    }


    public function testSslArguments(): void
    {
        $definition = grpcClient(self::client(['rootCa' => 'ca.pem', 'serverName' => 'temporal.local'], null), 'temporal.clients.default'); // @phpstan-ignore argument.type

        assertSame(['temporal:7233', 'ca.pem', null, null, 'temporal.local'], $definition->getArguments());
    }


    public function testApiKeyService(): void
    {
        $definition = grpcClient(self::client([], '@app.api_key'), 'temporal.clients.default'); // @phpstan-ignore argument.type

        assertEquals(['withAuthKey', [new Reference('app.api_key')], true], $definition->getMethodCalls()[0]);
    }


    public function testContextKeepsSdkDefaults(): void
    {
        $context = $this->context([]);

        assertSame(Context::default()->getMetadata(), $context->getMetadata());
        assertEquals(Context::default()->getRetryOptions(), $context->getRetryOptions());
    }


    public function testContextMetadataIsMergedIntoSdkHeaders(): void
    {
        $metadata = $this->context(['metadata' => ['x-team' => ['billing'], 'x-tags' => ['a', 1]]])->getMetadata();

        assertSame(['billing'], $metadata['x-team']);
        assertSame(['a', '1'], $metadata['x-tags']);
        assertSame(Context::default()->getMetadata()['client-name'], $metadata['client-name']);
    }


    public function testContextRetryOptionsChangeOnlyConfiguredValues(): void
    {
        $retry = $this->context(['retryOptions' => [
            'maximumInterval'           => '30 seconds',
            'backoffCoefficient'        => 1.5,
            'nonRetryableExceptions'    => [DomainException::class],
            'congestionInitialInterval' => '2 seconds',
            'maximumJitterCoefficient'  => 0.2,
        ]])->getRetryOptions();

        assertInstanceOf(RpcRetryOptions::class, $retry);
        assertSame(1.5, $retry->backoffCoefficient);
        assertSame(0, $retry->maximumAttempts);
        assertSame([DomainException::class], $retry->nonRetryableExceptions);
        assertSame(0.2, $retry->maximumJitterCoefficient);
        assertNotNull($retry->maximumInterval);
        assertSame(30, (int) CarbonInterval::instance($retry->maximumInterval)->totalSeconds);
        assertNotNull($retry->congestionInitialInterval);
        assertSame(2, (int) CarbonInterval::instance($retry->congestionInitialInterval)->totalSeconds);
        assertEquals(Context::default()->getRetryOptions()->initialInterval, $retry->initialInterval);
    }


    /**
     * @param array{metadata?: array<string, list<scalar>>, retryOptions?: array<string, mixed>} $grpcContext
     */
    private function context(array $grpcContext): Context
    {
        $container = new ContainerBuilder();

        $container->setDefinition('context', grpcContext([ // @phpstan-ignore argument.type
            'timeout'  => ['value' => 5, 'format' => 'seconds'],
            'options'  => [],
            'metadata' => [],
            ...$grpcContext,
            'retryOptions' => [...self::RETRY_OPTIONS, ...($grpcContext['retryOptions'] ?? [])],
        ]))->setPublic(true);

        $container->compile();

        $context = $container->get('context');

        assertInstanceOf(Context::class, $context);

        return $context;
    }


    /**
     * @param array<string, mixed> $tls
     *
     * @return array<string, mixed>
     */
    private static function client(array $tls, ?string $apiKey): array
    {
        return [
            'address'     => 'temporal:7233',
            'apiKey'      => $apiKey,
            'envConfig'   => ['enabled' => false, 'profile' => null, 'configFile' => null],
            'tls'         => [...['enabled' => null, 'rootCa' => null, 'clientKey' => null, 'clientPem' => null, 'serverName' => null], ...$tls],
            'grpcContext' => [
                'timeout'      => ['value' => 5, 'format' => 'seconds'],
                'options'      => [],
                'metadata'     => [],
                'retryOptions' => self::RETRY_OPTIONS,
            ],
        ];
    }
}
