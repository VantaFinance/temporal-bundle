<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Client;

use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stringable;
use Temporal\Client\GRPC\BaseClient;
use Temporal\Client\GRPC\Context;
use Vanta\Integration\Symfony\Temporal\Client\GrpcServiceClientFactory;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Interceptor\HeaderGrpcInterceptor;

#[CoversClass(GrpcServiceClientFactory::class)]
final class GrpcServiceClientFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        FakeServiceClient::$lastCall = null;
    }


    public function testInsecureWithoutCertificates(): void
    {
        $client = GrpcServiceClientFactory::create('localhost:7233', null, ['clientKey' => null, 'clientPem' => null], class: FakeServiceClient::class);

        assertInstanceOf(FakeServiceClient::class, $client);
        assertSame(['create', ['address' => 'localhost:7233']], FakeServiceClient::$lastCall);
    }


    public function testMutualTls(): void
    {
        GrpcServiceClientFactory::create('cloud:7233', null, ['clientKey' => 'key.pem', 'clientPem' => 'cert.pem'], class: FakeServiceClient::class);

        assertSame(['createSSL', [
            'address'            => 'cloud:7233',
            'crt'                => null,
            'clientKey'          => 'key.pem',
            'clientPem'          => 'cert.pem',
            'overrideServerName' => null,
        ]], FakeServiceClient::$lastCall);
    }


    public function testEmptyCertificatesAreInsecure(): void
    {
        GrpcServiceClientFactory::create('localhost:7233', null, ['clientKey' => '', 'clientPem' => ''], class: FakeServiceClient::class);

        assertSame('create', FakeServiceClient::$lastCall[0] ?? null);
    }


    /**
     * @return iterable<string, array{array<string, mixed>, Stringable|string|null, ?non-empty-string}>
     */
    public static function tlsDataProvider(): iterable
    {
        yield 'nothing' => [[], null, null];
        yield 'api key only' => [[], 'key', 'createSSL'];
        yield 'api key, tls disabled' => [['enabled' => false], 'key', 'create'];
        yield 'empty api key from env' => [[], '', 'create'];
        yield 'empty stringable api key' => [[], new class() implements Stringable {
            public function __toString(): string
            {
                return '';
            }
        }, 'create'];
        yield 'tls enabled without certificates' => [['enabled' => true], null, 'createSSL'];
        yield 'root ca and server name' => [['rootCa' => 'ca.pem', 'serverName' => 'temporal.local'], null, 'createSSL'];
        yield 'certificates, tls disabled' => [['enabled' => false, 'clientKey' => 'key.pem', 'clientPem' => 'cert.pem'], null, 'create'];

    }


    /**
     * @param array<string, mixed>      $tls
     * @param ?non-empty-string         $method
     */
    #[DataProvider('tlsDataProvider')]
    public function testTlsDecision(array $tls, Stringable|string|null $apiKey, ?string $method): void
    {
        /** @phpstan-ignore argument.type */
        GrpcServiceClientFactory::create('temporal:7233', $apiKey, $tls, class: FakeServiceClient::class);

        assertSame($method ?? 'create', FakeServiceClient::$lastCall[0] ?? null);
    }


    public function testRootCaAndServerNameArePassed(): void
    {
        GrpcServiceClientFactory::create('temporal:7233', null, ['rootCa' => 'ca.pem', 'serverName' => 'temporal.local', 'clientKey' => '', 'clientPem' => null], class: FakeServiceClient::class);

        assertSame(['createSSL', [
            'address'            => 'temporal:7233',
            'crt'                => 'ca.pem',
            'clientKey'          => null,
            'clientPem'          => null,
            'overrideServerName' => 'temporal.local',
        ]], FakeServiceClient::$lastCall);
    }


    public function testApiKeyIsAppliedOnlyWhenNotEmpty(): void
    {
        $key = new class() implements Stringable {
            public function __toString(): string
            {
                return 'secret';
            }
        };

        $client = GrpcServiceClientFactory::create('temporal:7233', $key, [], class: FakeServiceClient::class);

        assertSame($key, (new ReflectionProperty(BaseClient::class, 'apiKey'))->getValue($client));

        $client = GrpcServiceClientFactory::create('temporal:7233', '', [], class: FakeServiceClient::class);

        assertSame('', (new ReflectionProperty(BaseClient::class, 'apiKey'))->getValue($client));
    }


    public function testGrpcInterceptorPipelineIsApplied(): void
    {
        $pipeline = new ReflectionProperty(BaseClient::class, 'invokePipeline');

        $client = GrpcServiceClientFactory::create('temporal:7233', null, [], class: FakeServiceClient::class);

        assertNull($pipeline->getValue($client));

        $client = GrpcServiceClientFactory::create('temporal:7233', null, [], class: FakeServiceClient::class, interceptors: [new HeaderGrpcInterceptor()]);

        assertNotNull($pipeline->getValue($client));
    }


    public function testContextIsApplied(): void
    {
        $context = Context::default()->withTimeout(10);
        $client  = GrpcServiceClientFactory::create('localhost:7233', null, [], $context, FakeServiceClient::class);

        assertSame($context, $client->getContext());
    }
}
