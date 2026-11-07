<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use Closure;
use ReflectionMethod;
use ReflectionParameter;

use function sprintf;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * @internal
 *
 * @phpstan-type Kind 'class'|'method'|'property'|'parameter'
 * @phpstan-type Handler \Closure(Kind, string, ?string, ?string): bool
 */
final class SdkFeature
{
    /**
     * @var Handler|null
     */
    private static ?Closure $handler = null;


    /**
     * @param Handler|null $handler
     */
    public static function setHandler(?Closure $handler = null): void
    {
        self::$handler = $handler;
    }


    public static function hasClass(string $class): bool
    {
        return self::detect('class', $class, null, null);
    }


    public static function hasMethod(string $class, string $method): bool
    {
        return self::detect('method', $class, $method, null);
    }


    public static function hasProperty(string $class, string $property): bool
    {
        return self::detect('property', $class, $property, null);
    }


    public static function hasParameter(string $class, string $method, string $parameter): bool
    {
        return self::detect('parameter', $class, $method, $parameter);
    }


    public static function requireClass(string $class, string $optionPath, string $since): void
    {
        if (self::hasClass($class)) {
            return;
        }

        throw self::createException($optionPath, $since, $class);
    }


    public static function requireMethod(string $class, string $method, string $optionPath, string $since): void
    {
        if (self::hasMethod($class, $method)) {
            return;
        }

        throw self::createException($optionPath, $since, sprintf('%s::%s()', $class, $method));
    }


    public static function requireProperty(string $class, string $property, string $optionPath, string $since): void
    {
        if (self::hasProperty($class, $property)) {
            return;
        }

        throw self::createException($optionPath, $since, sprintf('%s::$%s', $class, $property));
    }


    public static function requireParameter(string $class, string $method, string $parameter, string $optionPath, string $since): void
    {
        if (self::hasParameter($class, $method, $parameter)) {
            return;
        }

        throw self::createException($optionPath, $since, sprintf('%s::%s($%s)', $class, $method, $parameter));
    }


    /**
     * @param Kind $kind
     */
    private static function detect(string $kind, string $class, ?string $member, ?string $parameter): bool
    {
        $handler = self::$handler;

        if ($handler !== null) {
            return $handler($kind, $class, $member, $parameter);
        }

        if (!class_exists($class) && !interface_exists($class) && !enum_exists($class)) {
            return false;
        }

        return match ($kind) {
            'class'     => true,
            'method'    => method_exists($class, (string) $member),
            'property'  => property_exists($class, (string) $member),
            'parameter' => method_exists($class, (string) $member) && in_array(
                $parameter,
                array_map(static fn (ReflectionParameter $reflection): string => $reflection->getName(), (new ReflectionMethod($class, (string) $member))->getParameters()),
                true,
            ),
        };
    }


    private static function createException(string $optionPath, string $since, string $symbol): InvalidConfigurationException
    {
        return new InvalidConfigurationException(sprintf(
            'Option "%s" requires temporal/sdk >= %s (%s not found). Run "composer require temporal/sdk:^%s" or remove the option.',
            $optionPath,
            $since,
            $symbol,
            $since,
        ));
    }
}
