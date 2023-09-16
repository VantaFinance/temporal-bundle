<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\ExceptionInterceptor;

use Temporal\Exception\ExceptionInterceptorInterface as ExceptionInterceptor;
use Throwable;

final class NullExceptionInterceptor implements ExceptionInterceptor
{
    public function isRetryable(Throwable $e): bool
    {
        return true;
    }
}
