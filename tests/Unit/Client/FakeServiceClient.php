<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Client;

use ReflectionClass;
use Temporal\Client\GRPC\ServiceClient;

/**
 * Records the factory call instead of opening a gRPC channel (ext-grpc is not required).
 */
final class FakeServiceClient extends ServiceClient
{
    /**
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    public static ?array $lastCall = null;


    private static function instance(): static
    {
        // The SDK constructor opens the connection
        return (new ReflectionClass(static::class))->newInstanceWithoutConstructor();
    }


    public static function create(string $address): static
    {
        self::$lastCall = ['create', ['address' => $address]];

        return self::instance();
    }


    public static function createSSL(
        string $address,
        ?string $crt = null,
        ?string $clientKey = null,
        ?string $clientPem = null,
        ?string $overrideServerName = null,
    ): static {
        self::$lastCall = ['createSSL', [
            'address'            => $address,
            'crt'                => $crt,
            'clientKey'          => $clientKey,
            'clientPem'          => $clientPem,
            'overrideServerName' => $overrideServerName,
        ]];

        return self::instance();
    }
}
