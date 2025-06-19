<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use React\Promise\PromiseInterface as Promise;
use ReflectionObject;
use Temporal\Interceptor\Trait\WorkflowClientCallsInterceptorTrait;
use Temporal\Interceptor\Trait\WorkflowOutboundRequestInterceptorTrait;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartOutput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\Interceptor\WorkflowOutboundRequestInterceptor;
use Temporal\Internal\Transport\Request\ExecuteChildWorkflow;
use Temporal\Workflow\WorkflowExecution;

final class DisableSearchAttributesInterceptor implements WorkflowOutboundRequestInterceptor, WorkflowClientCallsInterceptor
{
    use WorkflowOutboundRequestInterceptorTrait;
    use WorkflowClientCallsInterceptorTrait;

    protected function executeChildWorkflowRequest(ExecuteChildWorkflow $request, callable $next): Promise
    {
        $newOptions                                = $request->getOptions();
        $newOptions['options']['SearchAttributes'] = null; //@phpstan-ignore offsetAccess.nonOffsetAccessible

        // understand and forgive 🥺, used because of lastID
        $reflection = new ReflectionObject($request);
        $reflection->getProperty('options')->setValue($request, $newOptions);

        return $next($request);
    }

    public function start(StartInput $input, callable $next): WorkflowExecution
    {
        return $next($input->with(options: $input->options->withSearchAttributes(null)));
    }


    public function signalWithStart(SignalWithStartInput $input, callable $next): WorkflowExecution
    {
        return $next(
            $input->with(
                workflowStartInput: $input->workflowStartInput->with(
                    options: $input->workflowStartInput->options->withSearchAttributes(null)
                )
            )
        );
    }

    public function updateWithStart(UpdateWithStartInput $input, callable $next): UpdateWithStartOutput
    {
        /**@phpstan-ignore-next-line */
        return $next(
            $input->with(
                workflowStartInput: $input->workflowStartInput->with(
                    options: $input->workflowStartInput->options->withSearchAttributes(null)
                )
            )
        );
    }
}
