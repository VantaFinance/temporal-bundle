<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use Composer\InstalledVersions;
use InvalidArgumentException;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use RuntimeException;
use Temporal\Common\SearchAttributes\ValueType;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;

final readonly class TemporalTestEnvironmentFactory
{
    public static function fromAttribute(WithTemporalEnvironment $attribute, ?string $projectDirectory = null, Logger $logger = new NullLogger()): TemporalTestEnvironment
    {
        if ($attribute->temporal != TemporalServerType::Dev) {
            if ($attribute->searchAttributes != []) {
                throw new InvalidArgumentException('Search attributes are registered only by the Dev server (TemporalServerType::Dev).');
            }

            if ($attribute->temporalNamespace != 'default' || $attribute->temporalDynamicConfig != [] || $attribute->temporalArguments != []) {
                throw new InvalidArgumentException('temporalNamespace, temporalDynamicConfig and temporalArguments are supported only by the Dev server (TemporalServerType::Dev).');
            }
        }

        $projectDirectory ??= self::projectDirectory();

        // RoadRunner must talk to the same Temporal address and namespace the attribute starts
        $overrides = $attribute->roadRunnerOverrides + [
            'temporal.address'   => $attribute->temporalAddress,
            'temporal.namespace' => $attribute->temporalNamespace,
        ];

        $logger->debug('Building test environment from attribute', [
            'projectDirectory' => $projectDirectory,
            'temporal'         => $attribute->temporal->value,
            'temporalAddress'  => $attribute->temporalAddress,
            'searchAttributes' => array_map(static fn (ValueType $type): string => $type->name, $attribute->searchAttributes),
            'namespace'        => $attribute->temporalNamespace,
            'dynamicConfig'    => $attribute->temporalDynamicConfig,
            'arguments'        => $attribute->temporalArguments,
            'roadRunnerConfig' => $attribute->roadRunnerConfig,
            'overrides'        => $overrides,
        ]);

        $servers = [];

        if ($attribute->temporal == TemporalServerType::Dev) {
            $servers[] = new TemporalDevServer(
                binary: self::binary($attribute->temporalBinary ?? 'temporal', $projectDirectory),
                address: $attribute->temporalAddress,
                namespace: $attribute->temporalNamespace,
                searchAttributes: $attribute->searchAttributes,
                dynamicConfig: $attribute->temporalDynamicConfig,
                extraArguments: $attribute->temporalArguments,
                startTimeout: $attribute->startTimeout,
                stopTimeout: $attribute->stopTimeout,
                binaryDirectories: [$projectDirectory],
                logger: $logger,
            );
        }

        if ($attribute->temporal == TemporalServerType::TimeSkipping) {
            $servers[] = new TemporalTestServer(
                binary: self::binary($attribute->temporalBinary ?? 'temporal-test-server', $projectDirectory),
                port: self::port($attribute->temporalAddress),
                startTimeout: $attribute->startTimeout,
                stopTimeout: $attribute->stopTimeout,
                binaryDirectories: [$projectDirectory],
                logger: $logger,
            );
        }

        $servers[] = new RoadRunnerServer(
            configFile: self::path($attribute->roadRunnerConfig, $projectDirectory),
            binary: self::binary($attribute->roadRunnerBinary ?? 'rr', $projectDirectory),
            overrides: $overrides,
            startTimeout: $attribute->startTimeout,
            stopTimeout: $attribute->stopTimeout,
            binaryDirectories: [$projectDirectory],
            logger: $logger,
        );

        return (new TemporalTestEnvironment(...$servers))->withLogger($logger);
    }

    private static function projectDirectory(): string
    {
        $directory = realpath(InstalledVersions::getRootPackage()['install_path']);

        if ($directory === false) {
            throw new RuntimeException('Unable to determine the project root directory from Composer.');
        }

        return $directory;
    }

    /**
     * A bare name is looked up in PATH and the project root; a relative path is relative to the project root.
     */
    private static function binary(string $binary, string $projectDirectory): string
    {
        return str_contains($binary, '/') ? self::path($binary, $projectDirectory) : $binary;
    }

    private static function path(string $path, string $projectDirectory): string
    {
        return str_starts_with($path, '/') ? $path : $projectDirectory . '/' . $path;
    }

    private static function port(string $address): int
    {
        $port = parse_url('tcp://' . $address, PHP_URL_PORT);

        if (!is_int($port)) {
            throw new InvalidArgumentException(sprintf('Invalid Temporal address "%s", expected "host:port".', $address));
        }

        return $port;
    }
}
