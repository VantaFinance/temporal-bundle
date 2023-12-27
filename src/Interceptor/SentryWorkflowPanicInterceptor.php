<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use React\Promise\PromiseInterface as Promise;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\StacktraceBuilder;
use Sentry\State\HubInterface as Hub;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Temporal\Workflow;

final readonly class SentryWorkflowPanicInterceptor implements WorkflowOutboundCallsInterceptor
{
    use WorkflowOutboundCallsInterceptorTrait;

    public function __construct(
        private Hub $hub,
        private StacktraceBuilder $stacktraceBuilder,
    ) {
    }


    public function panic(PanicInput $input, callable $next): Promise
    {
        $failure = $input->failure;

        if ($failure == null) {
            return $next($input);
        }

        $stackTrace = $this->stacktraceBuilder->buildFromException($failure);

        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($failure, $stackTrace)]);
        $event->setContext('Workflow', [
            'Id'        => Workflow::getInfo()->execution->getID(),
            'Type'      => Workflow::getInfo()->type,
            'Namespace' => Workflow::getInfo()->namespace,
            'TaskQueue' => Workflow::getInfo()->taskQueue,
        ]);
        $eventHit = EventHint::fromArray(['exception' => $failure]);

        $this->hub->captureEvent($event, $eventHit);

        return $next($input);
    }
}
