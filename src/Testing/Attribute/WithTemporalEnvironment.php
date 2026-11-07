<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Attribute;

use Attribute;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Temporal\Common\SearchAttributes\ValueType;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalServerType;

/**
 * Starts Temporal and RoadRunner before every test it applies to and stops them afterwards.
 *
 * A method attribute overrides the class attribute; a class inherits the attribute of its parents.
 * Relative paths are resolved against the project root.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithTemporalEnvironment
{
    /**
     * @param array<non-empty-string, ValueType> $searchAttributes      registered by the Dev server only
     * @param array<string, mixed>               $temporalDynamicConfig `--dynamic-config-value KEY=<json>` (Dev only)
     * @param list<string>                       $temporalArguments     extra `temporal server start-dev` flags (Dev only)
     * @param array<non-empty-string, string>    $roadRunnerOverrides config values passed to RoadRunner as `-o key=value`
     */
    public function __construct(
        public TemporalServerType $temporal = TemporalServerType::Dev,
        public string $temporalAddress = '127.0.0.1:7233',
        public ?string $temporalBinary = null,
        public array $searchAttributes = [],
        public string $temporalNamespace = 'default',
        public array $temporalDynamicConfig = [],
        public array $temporalArguments = [],
        public string $roadRunnerConfig = '.rr.temporal.testing.yaml',
        public ?string $roadRunnerBinary = null,
        public array $roadRunnerOverrides = [],
        public float $startTimeout = 30.0,
        public float $stopTimeout = 10.0,
    ) {
    }

    /**
     * @param class-string $class
     */
    public static function resolve(string $class, string $method): ?self
    {
        try {
            $reflectionMethod = new ReflectionMethod($class, $method);

            foreach ($reflectionMethod->getAttributes(self::class) as $attribute) {
                return $attribute->newInstance();
            }
        } catch (ReflectionException) {
            // Data-provider names and dynamic tests may not map to a method: fall back to the class
        }

        for ($reflectionClass = new ReflectionClass($class); $reflectionClass !== false; $reflectionClass = $reflectionClass->getParentClass()) {
            foreach ($reflectionClass->getAttributes(self::class) as $attribute) {
                return $attribute->newInstance();
            }
        }

        return null;
    }
}
