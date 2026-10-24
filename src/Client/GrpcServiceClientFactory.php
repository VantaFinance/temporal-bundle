<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Client;

use Stringable;
use Temporal\Client\GRPC\BaseClient;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;

/**
 * @internal
 */
final class GrpcServiceClientFactory
{
    /**
     * @template T of BaseClient
     *
     * @param non-empty-string $address
     * @param array{
     *   enabled?: ?bool,
     *   rootCa?: ?string,
     *   clientKey?: ?string,
     *   clientPem?: ?string,
     *   serverName?: ?string,
     * } $tls
     * @param class-string<T>             $class
     * @param list<GrpcClientInterceptor> $interceptors
     *
     * @return T
     */
    public static function create(
        string $address,
        Stringable|string|null $apiKey,
        array $tls,
        ?ContextInterface $context = null,
        string $class = ServiceClient::class,
        array $interceptors = [],
    ): BaseClient {
        $hasApiKey = $apiKey !== null && (string) $apiKey !== '';
        $ssl       = [
            'crt'                => self::nonEmpty($tls['rootCa'] ?? null),
            'clientKey'          => self::nonEmpty($tls['clientKey'] ?? null),
            'clientPem'          => self::nonEmpty($tls['clientPem'] ?? null),
            'overrideServerName' => self::nonEmpty($tls['serverName'] ?? null),
        ];

        $enabled = $tls['enabled'] ?? null;

        // An API key must not be sent over an insecure connection
        $useTls = $enabled ?? ($hasApiKey || array_filter($ssl) != []);

        $client = $useTls ? $class::createSSL($address, ...$ssl) : $class::create($address);

        if ($hasApiKey) {
            $client = $client->withAuthKey($apiKey);
        }

        if ($context !== null) {
            $client = $client->withContext($context);
        }

        // The pipeline is bound to the client instance: add it last
        if ($interceptors != []) {
            $client = $client->withInterceptorPipeline((new SimplePipelineProvider($interceptors))->getPipeline(GrpcClientInterceptor::class)); // @phpstan-ignore argument.type
        }

        return $client;
    }


    /**
     * @return non-empty-string|null
     */
    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }
}
