<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use Doctrine\ORM\Exception\EntityManagerClosed;
use React\Promise\PromiseInterface;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\DoctrinePingConnectionExceptionInterceptor;

final readonly class DoctrineWorkflowPanicInterceptor implements WorkflowOutboundCallsInterceptor
{
    use WorkflowOutboundCallsInterceptorTrait;

    public function __construct(
        private DoctrinePingConnectionExceptionInterceptor $interceptor
    ) {
    }


    public function panic(PanicInput $input, callable $next): PromiseInterface
    {
        if ($input->failure instanceof EntityManagerClosed) {
            $this->interceptor->isRetryable($input->failure);
        }

        return $next($input);
    }
}