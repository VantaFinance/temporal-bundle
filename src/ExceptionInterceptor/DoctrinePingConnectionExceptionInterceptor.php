<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\ExceptionInterceptor;

use Doctrine\ORM\Exception\EntityManagerClosed;
use Psr\Log\LoggerInterface as Logger;
use Psr\Log\NullLogger;
use Temporal\Exception\ExceptionInterceptorInterface as ExceptionInterceptor;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrinePingConnectionFinalizer;

final readonly class DoctrinePingConnectionExceptionInterceptor implements ExceptionInterceptor
{
    public function __construct(
        private ExceptionInterceptor $interceptor,
        private DoctrinePingConnectionFinalizer $finalizer,
        private Logger $logger = new NullLogger()
    ) {
    }


    public function isRetryable(Throwable $e): bool
    {
        if ($e instanceof EntityManagerClosed) {
            try {
                $this->finalizer->finalize();
            } catch (Throwable $e) {
                $this->logger->error($e->getMessage(), ['throwable' => $e]);
            }
        }

        return $this->interceptor->isRetryable($e);
    }
}
