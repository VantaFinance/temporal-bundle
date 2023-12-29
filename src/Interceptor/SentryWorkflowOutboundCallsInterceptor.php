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
use Temporal\Interceptor\WorkflowOutboundCalls\CompleteInput;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;
use Temporal\Workflow;
use Throwable;

final readonly class SentryWorkflowOutboundCallsInterceptor implements WorkflowOutboundCallsInterceptor
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

        $this->reportError($failure);

        return $next($input);
    }


    public function complete(CompleteInput $input, callable $next): Promise
    {
        $failure = $input->failure;

        if ($failure == null) {
            return $next($input);
        }

        $this->reportError($failure);

        return $next($input);
    }

    private function reportError(Throwable $e): void
    {
        $stackTrace = $this->stacktraceBuilder->buildFromException($e);

        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($e, $stackTrace)]);
        $event->setContext('Workflow', [
            'Id'        => Workflow::getInfo()->execution->getID(),
            'Type'      => Workflow::getInfo()->type->name,
            'Namespace' => Workflow::getInfo()->namespace,
            'TaskQueue' => Workflow::getInfo()->taskQueue,
        ]);

        $request = [];

        foreach (Workflow::getInput()->getValues() as $value) {
            $request[] = $value;
        }

        $event->setExtra(['Args' => $request]);

        $eventHit = EventHint::fromArray(['exception' => $e, 'stacktrace' => $stackTrace]);

        $this->hub->captureEvent($event, $eventHit);
    }
}
