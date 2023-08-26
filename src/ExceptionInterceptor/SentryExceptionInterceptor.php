<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\ExceptionInterceptor;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;

use function Sentry\captureException;

use Temporal\Exception\ExceptionInterceptorInterface as ExceptionInterceptor;
use Throwable;

final readonly class SentryExceptionInterceptor implements ExceptionInterceptor
{
    public function __construct(
        private ExceptionInterceptor $interceptor,
        private Logger $logger = new NullLogger()
    ) {
    }


    public function isRetryable(Throwable $e): bool
    {
        try {
            captureException($e);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['throwable' => $e]);
        }

        return $this->interceptor->isRetryable($e);
    }
}
