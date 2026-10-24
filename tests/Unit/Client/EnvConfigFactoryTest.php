<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Client;

use InvalidArgumentException;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Temporal\Client\GRPC\BaseClient;
use Vanta\Integration\Symfony\Temporal\Client\EnvConfigFactory;

#[CoversClass(EnvConfigFactory::class)]
final class EnvConfigFactoryTest extends TestCase
{
    private const ENV = ['TEMPORAL_ADDRESS', 'TEMPORAL_NAMESPACE', 'TEMPORAL_API_KEY', 'TEMPORAL_PROFILE', 'TEMPORAL_CONFIG_FILE', 'TEMPORAL_GRPC_META_X_TEAM', 'HOME', 'XDG_CONFIG_HOME', 'APPDATA'];

    /**
     * @var array<string, mixed>
     */
    private array $server = [];


    protected function setUp(): void
    {
        if (!class_exists('Temporal\Common\EnvConfig\ConfigClient')) {
            self::markTestSkipped('Environment configuration requires temporal/sdk >= 2.17');
        }

        $this->server = $_SERVER;

        foreach (self::ENV as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }

        // No default config file of the machine running the tests
        $_SERVER['HOME'] = $_SERVER['XDG_CONFIG_HOME'] = $_SERVER['APPDATA'] = '/nonexistent';
    }


    protected function tearDown(): void
    {
        $_SERVER = $this->server;

        foreach (self::ENV as $name) {
            unset($_ENV[$name]);
        }

        FakeServiceClient::$lastCall = null;
    }


    public function testProfileFromEnvVariables(): void
    {
        $_SERVER['TEMPORAL_ADDRESS']          = 'cloud.tmprl:7233';
        $_SERVER['TEMPORAL_NAMESPACE']        = 'billing';
        $_SERVER['TEMPORAL_API_KEY']          = 'secret';
        $_SERVER['TEMPORAL_GRPC_META_X_TEAM'] = 'payments';

        $client = EnvConfigFactory::serviceClient(null, null, class: FakeServiceClient::class);

        // An API key turns TLS on (temporal/sdk environment configuration rule)
        assertSame('createSSL', FakeServiceClient::$lastCall[0] ?? null);
        assertSame('cloud.tmprl:7233', FakeServiceClient::$lastCall[1]['address'] ?? null);
        assertSame('secret', (new ReflectionProperty(BaseClient::class, 'apiKey'))->getValue($client));
        assertSame(['payments'], $client->getContext()->getMetadata()['x-team'] ?? null);

        assertSame('billing', EnvConfigFactory::clientOptions(null, null)->namespace);
        assertSame('override', EnvConfigFactory::clientOptions(null, null, 'override')->namespace);
    }


    public function testProfileFromToml(): void
    {
        if (!class_exists('Internal\Toml\Toml')) {
            self::markTestSkipped('TOML profiles require internal/toml');
        }

        $toml = <<<'TOML'
            [profile.default]
            address = "localhost:7233"
            namespace = "default"

            [profile.staging]
            address = "staging:7233"
            namespace = "staging"
            TOML;

        EnvConfigFactory::serviceClient('staging', $toml, class: FakeServiceClient::class);

        assertSame(['create', ['address' => 'staging:7233']], FakeServiceClient::$lastCall);
        assertSame('staging', EnvConfigFactory::clientOptions('staging', $toml)->namespace);
    }


    public function testMissingAddressFails(): void
    {
        $_SERVER['TEMPORAL_NAMESPACE'] = 'billing';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Temporal address is not configured');

        EnvConfigFactory::serviceClient(null, null, class: FakeServiceClient::class);
    }
}
