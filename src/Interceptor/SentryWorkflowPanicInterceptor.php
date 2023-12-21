<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use React\Promise\PromiseInterface;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\SentryExceptionInterceptor;

final readonly class SentryWorkflowPanicInterceptor implements WorkflowOutboundCallsInterceptor
{
    use WorkflowOutboundCallsInterceptorTrait;

    public function __construct(
        private SentryExceptionInterceptor $interceptor
    ) {
    }


    public function panic(PanicInput $input, callable $next): PromiseInterface
    {
        $this->interceptor->isRetryable($input->failure);

        return $next($input);
    }
}