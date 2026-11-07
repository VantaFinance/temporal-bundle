<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Interceptor;

use Temporal\Client\GRPC\ContextInterface;
use Temporal\Interceptor\GrpcClientInterceptor;

final class HeaderGrpcInterceptor implements GrpcClientInterceptor
{
    public function interceptCall(string $method, object $arg, ContextInterface $ctx, callable $next): object
    {
        return $next($method, $arg, $ctx->withMetadata([...$ctx->getMetadata(), 'x-test' => ['1']]));
    }
}
