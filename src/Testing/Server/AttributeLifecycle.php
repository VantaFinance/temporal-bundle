<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Server;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;

/**
 * Starts the environment described by {@see WithTemporalEnvironment} for one test and stops it afterwards.
 *
 * Shared by the PHPUnit and Codeception extensions.
 *
 * @internal
 */
final class AttributeLifecycle
{
    private ?TemporalTestEnvironment $current = null;

    public function __construct(
        private readonly Logger $logger = new NullLogger(),
    ) {
    }

    /**
     * @param class-string $class
     */
    public function start(string $class, string $method): void
    {
        // The adapter may have missed the "test finished" event of the previous test
        $this->stop();

        $attribute = WithTemporalEnvironment::resolve($class, $method);

        if ($attribute == null) {
            $this->logger->debug(sprintf('No #[WithTemporalEnvironment] on %s::%s', $class, $method));

            return;
        }

        $this->logger->info(sprintf('Starting test environment for %s::%s', $class, $method));

        $environment = TemporalTestEnvironmentFactory::fromAttribute($attribute, null, $this->logger);
        $environment->start();

        $this->current = $environment;
    }

    public function stop(): void
    {
        try {
            $this->current?->stop();
        } finally {
            $this->current = null;
        }
    }

    public function current(): ?TemporalTestEnvironment
    {
        return $this->current;
    }
}
