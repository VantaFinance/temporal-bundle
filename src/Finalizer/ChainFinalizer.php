<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Finalizer;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Throwable;

final class ChainFinalizer implements Finalizer
{
    private Logger $logger;

    /**
     * @param iterable<Finalizer> $finalizers
     */
    public function __construct(
        private readonly iterable $finalizers,
        ?Logger $logger = new NullLogger(),
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function finalize(): void
    {
        foreach ($this->finalizers as $finalizer) {
            try {
                $finalizer->finalize();
            } catch (Throwable $e) {
                $this->logger->critical('Failed to finalize the finalizer', [
                    'exception' => $e,
                ]);
            }
        }
    }
}
