<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\ExceptionInterceptor;

use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Sentry\State\HubInterface as Hub;
use Temporal\Exception\ExceptionInterceptorInterface as ExceptionInterceptor;
use Throwable;

final readonly class SentryExceptionInterceptor implements ExceptionInterceptor
{
    public function __construct(
        private ExceptionInterceptor $interceptor,
        private Hub $hub,
        private Logger $logger = new NullLogger()
    ) {
    }


    public function isRetryable(Throwable $e): bool
    {
        try {
            $this->hub->captureException($e);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['throwable' => $e]);
        }

        return $this->interceptor->isRetryable($e);
    }
}
