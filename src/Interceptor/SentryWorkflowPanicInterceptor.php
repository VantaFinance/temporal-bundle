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
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\State\HubInterface as Hub;
use Temporal\Interceptor\Trait\WorkflowOutboundCallsInterceptorTrait;
use Temporal\Interceptor\WorkflowOutboundCalls\PanicInput;
use Temporal\Interceptor\WorkflowOutboundCallsInterceptor;

final readonly class SentryWorkflowPanicInterceptor implements WorkflowOutboundCallsInterceptor
{
    use WorkflowOutboundCallsInterceptorTrait;

    public function __construct(
        private Hub $hub
    ) {
    }


    public function panic(PanicInput $input, callable $next): PromiseInterface
    {
        $failure = $input->failure;

        if ($failure == null) {
            return $next($input);
        }

        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag($failure)]);

        $eventHit = EventHint::fromArray(['exception' => $failure]);

        $this->hub->captureEvent($event, $eventHit);

        return $next($input);
    }
}
