<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use function iterator_to_array;

use Sentry\Event;

use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\StacktraceBuilder;
use Sentry\State\HubInterface as Hub;
use Temporal\Activity;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;
use Throwable;

final readonly class SentryActivityInboundInterceptor implements ActivityInboundInterceptor
{
    public function __construct(
        private Hub $hub,
        private StacktraceBuilder $stacktraceBuilder,
    ) {
    }


    /**
     * @throws Throwable
     */
    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        try {
            $result = $next($input);
        } catch (Throwable $e) {
            $stackTrace = $this->stacktraceBuilder->buildFromException($e);

            $event = Event::createEvent();
            $event->setExceptions([new ExceptionDataBag($e, $stackTrace)]);
            $event->setContext('Activity', [
                'Id'        => Activity::getInfo()->id,
                'Type'      => Activity::getInfo()->type->name,
                'TaskQueue' => Activity::getInfo()->taskQueue,
                'Headers'   => iterator_to_array($input->header->getIterator()),
            ]);
            $event->setContext('Workflow', [
                'Namespace' => Activity::getInfo()->workflowNamespace,
                'Type'      => Activity::getInfo()->workflowType?->name,
                'Id'        => Activity::getInfo()->workflowExecution?->getID(),
            ]);

            $eventHit = EventHint::fromArray(['exception' => $e]);

            $this->hub->captureEvent($event, $eventHit);

            throw $e;
        }

        return $result;
    }
}
