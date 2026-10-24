<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Client;

use InvalidArgumentException;
use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\BaseClient;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Common\EnvConfig\Client\ConfigProfile;
use Temporal\Common\EnvConfig\ConfigClient;
use Temporal\Interceptor\GrpcClientInterceptor;

/**
 * @internal
 */
final class EnvConfigFactory
{
    /**
     * @template T of BaseClient
     *
     * @param non-empty-string|null       $profile
     * @param non-empty-string|null       $configFile
     * @param class-string<T>             $class
     * @param list<GrpcClientInterceptor> $interceptors
     *
     * @return T
     */
    public static function serviceClient(
        ?string $profile,
        ?string $configFile,
        ?ContextInterface $context = null,
        string $class = ServiceClient::class,
        array $interceptors = [],
    ): BaseClient {
        $config = self::load($profile, $configFile);

        if ($config->address === null) {
            throw new InvalidArgumentException(sprintf('The Temporal address is not configured in the environment configuration profile "%s": set TEMPORAL_ADDRESS or the profile "address".', $profile ?? 'default'));
        }

        $tls = $config->tlsConfig;

        if ($config->grpcMeta !== []) {
            $context ??= Context::default();
            $context = $context->withMetadata([...$context->getMetadata(), ...$config->grpcMeta]);
        }

        return GrpcServiceClientFactory::create(
            $config->address,
            $config->apiKey,
            [
                'enabled'    => $tls !== null && !($tls->disabled ?? false),
                'rootCa'     => $tls?->rootCerts,
                'clientKey'  => $tls?->privateKey,
                'clientPem'  => $tls?->certChain,
                'serverName' => $tls?->serverName,
            ],
            $context,
            $class,
            $interceptors,
        );
    }


    /**
     * @param non-empty-string|null $profile
     * @param non-empty-string|null $configFile
     * @param non-empty-string|null $namespace
     */
    public static function clientOptions(?string $profile, ?string $configFile, ?string $namespace = null): ClientOptions
    {
        return (new ClientOptions())->withNamespace($namespace ?? self::load($profile, $configFile)->namespace ?? ClientOptions::DEFAULT_NAMESPACE);
    }


    /**
     * @param non-empty-string|null $profile
     * @param non-empty-string|null $configFile
     */
    private static function load(?string $profile, ?string $configFile): ConfigProfile
    {
        // The SDK parses a missing file path as TOML content
        if ($configFile !== null && !str_contains($configFile, "\n") && !is_file($configFile)) {
            throw new InvalidArgumentException(sprintf('Temporal environment configuration file "%s" not found', $configFile));
        }

        // Symfony Dotenv fills $_ENV / $_SERVER without putenv()
        $env = array_filter([...$_SERVER, ...$_ENV], static fn (mixed $value): bool => is_string($value));

        return ConfigClient::load($profile, $configFile, $env);
    }
}
