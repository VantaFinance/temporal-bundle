<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Interceptor;

use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Contracts\Service\ResetInterface as Reset;
use Temporal\Client\Update\WaitPolicy;
use Temporal\Client\Workflow\WorkflowExecutionDescription;
use Temporal\DataConverter\ValuesInterface;
use Temporal\Interceptor\WorkflowClient\CancelInput;
use Temporal\Interceptor\WorkflowClient\DescribeInput;
use Temporal\Interceptor\WorkflowClient\GetResultInput;
use Temporal\Interceptor\WorkflowClient\QueryInput;
use Temporal\Interceptor\WorkflowClient\SignalInput;
use Temporal\Interceptor\WorkflowClient\SignalWithStartInput;
use Temporal\Interceptor\WorkflowClient\StartInput;
use Temporal\Interceptor\WorkflowClient\StartUpdateOutput;
use Temporal\Interceptor\WorkflowClient\TerminateInput;
use Temporal\Interceptor\WorkflowClient\UpdateInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartInput;
use Temporal\Interceptor\WorkflowClient\UpdateWithStartOutput;
use Temporal\Interceptor\WorkflowClientCallsInterceptor;
use Temporal\Workflow\WorkflowExecution;

/**
 * @phpstan-type StartedWorkflow array{
 *    workflowOptions: Data,
 *    workflowId: string,
 *    workflowType: string,
 *    workflowHeaders:  Data,
 *    workflowArguments: Data
 * }
 *
 * @phpstan-type SendUpdate array{
 *   workflowType: string|null,
 *   workflowHeaders: Data,
 *   workflowArguments: Data,
 *   workflowRunId: string|null,
 *   workflowId:  string,
 *   updateId: string,
 *   update: string,
 *   firstExecutionRunId: string,
 *   resultType: Data,
 *   waitPolicy: WaitPolicy
 * }
 *
 * @phpstan-type SendSignal array{
 *   workflowType: string|null,
 *   signal: string,
 *   workflowId: string,
 *   workflowRunId: string|null,
 *   signalArguments: Data
 * }
 *
 *
 * @phpstan-type StartedWorkflowWithSignal array{
 *   workflowType: string,
 *   workflowId: string,
 *   workflowOptions: Data,
 *   workflowArguments: Data,
 *   workflowHeaders: Data,
 *   signalArguments: Data,
 *   signal:string,
 * }
 *
 * @phpstan-type StartedWorkflowWithUpdate array{
 *    workflowType: string,
 *    workflowId: string,
 *    workflowRunId: string|null,
 *    workflowOptions: Data,
 *    workflowArguments: Data,
 *    workflowHeaders: Data,
 *    update: string,
 *    updateArguments: Data
 * }
 *
 *
 * @phpstan-type GetResultWorkflow array{
 *   workflowType: string|null,
 *   workflowId: string,
 *   workflowRunId: string|null,
 *   timeout: int|null,
 *   returnType: Data
 * }
 *
 *
 * @phpstan-type SendQuery array{
 *  workflowType: string|null,
 *  workflowId: string,
 *  workflowRunId: string|null,
 *  query: string,
 *  queryArguments: Data
 * }
 *
 * @phpstan-type SendCancelWorkflow array{
 *   workflowId: string,
 *   workflowRunId: string|null
 * }
 *
 * @phpstan-type SendTerminateWorkflow array{
 *    workflowId: string,
 *    workflowRunId: string|null
 *  }
 *
 * @phpstan-type SendDescribe array{
 *   workflowId: string,
 *   workflowRunId: string|null,
 *   namespace: string
 * }
 *
 */
final class ProfilerWorkflowInterceptor implements WorkflowClientCallsInterceptor, Reset
{
    /**
     * @param non-empty-string                 $clientName
     * @param array<StartedWorkflow>           $startedWorkflow
     * @param array<SendSignal>                $sendSignal
     * @param array<SendUpdate>                $sendUpdate
     * @param array<StartedWorkflowWithSignal> $startedWorkflowWithSignal
     * @param array<StartedWorkflowWithUpdate> $startedWorkflowWithUpdate
     * @param array<GetResultWorkflow>         $getResultWorkflow
     * @param array<SendQuery>                 $sendQuery
     * @param array<SendCancelWorkflow>        $sendCancelWorkflow
     * @param array<SendTerminateWorkflow>     $sendTerminateWorkflow
     * @param array<SendDescribe>              $sendDescribe
     */
    public function __construct(
        public readonly string $clientName,
        private array $startedWorkflow = [],
        private array $sendSignal = [],
        private array $sendUpdate = [],
        private array $startedWorkflowWithSignal = [],
        private array $startedWorkflowWithUpdate = [],
        private array $getResultWorkflow = [],
        private array $sendQuery = [],
        private array $sendCancelWorkflow = [],
        private array $sendTerminateWorkflow = [],
        private array $sendDescribe = [],
    ) {
    }


    public function start(StartInput $input, callable $next): WorkflowExecution
    {
        $varCloner = new VarCloner();

        $this->startedWorkflow[] = [
            'workflowOptions'   => $varCloner->cloneVar($input->options),
            'workflowId'        => $input->workflowId,
            'workflowType'      => $input->workflowType,
            'workflowHeaders'   => $varCloner->cloneVar(iterator_to_array($input->header->getIterator())),
            'workflowArguments' => $varCloner->cloneVar(iterator_to_array($input->arguments->getValues())),
        ];

        return $next($input);
    }

    public function signal(SignalInput $input, callable $next): void
    {
        $varCloner = new VarCloner();

        $this->sendSignal[] = [
            'workflowType'    => $input->workflowType,
            'signal'          => $input->signalName,
            'workflowId'      => $input->workflowExecution->getID(),
            'workflowRunId'   => $input->workflowExecution->getRunID(),
            'signalArguments' => $varCloner->cloneVar(iterator_to_array($input->arguments->getValues())),
        ];

        $next($input);
    }

    public function update(UpdateInput $input, callable $next): StartUpdateOutput
    {
        $varCloner = new VarCloner();


        $this->sendUpdate[] = [
            'workflowType'        => $input->workflowType,
            'workflowHeaders'     => $varCloner->cloneVar(iterator_to_array($input->header->getIterator())),
            'workflowArguments'   => $varCloner->cloneVar(iterator_to_array($input->arguments->getValues())),
            'workflowRunId'       => $input->workflowExecution->getRunID(),
            'workflowId'          => $input->workflowExecution->getID(),
            'updateId'            => $input->updateId,
            'firstExecutionRunId' => $input->firstExecutionRunId,
            'update'              => $input->updateName,
            'updateArguments'     => $varCloner->cloneVar($input->arguments->getValues()),
            'resultType'          => $varCloner->cloneVar($input->resultType),
            'waitPolicy'          => $input->waitPolicy,
        ];

        return $next($input);
    }

    public function signalWithStart(SignalWithStartInput $input, callable $next): WorkflowExecution
    {
        $varCloner = new VarCloner();

        $this->startedWorkflowWithSignal[] = [
            'workflowType'      => $input->workflowStartInput->workflowType,
            'signal'            => $input->signalName,
            'workflowId'        => $input->workflowStartInput->workflowId,
            'workflowOptions'   => $varCloner->cloneVar($input->workflowStartInput->options),
            'workflowHeaders'   => $varCloner->cloneVar(iterator_to_array($input->workflowStartInput->header->getIterator())),
            'workflowArguments' => $varCloner->cloneVar(iterator_to_array($input->workflowStartInput->arguments->getValues())),
            'signalArguments'   => $varCloner->cloneVar(iterator_to_array($input->signalArguments->getValues())),
        ];

        return $next($input);
    }

    public function updateWithStart(UpdateWithStartInput $input, callable $next): UpdateWithStartOutput
    {
        $varCloner = new VarCloner();

        $this->startedWorkflowWithUpdate[] = [
            'workflowType'      => $input->workflowStartInput->workflowType,
            'update'            => $input->updateInput->updateName,
            'updateArguments'   => $varCloner->cloneVar(iterator_to_array($input->updateInput->arguments->getValues())),
            'workflowId'        => $input->updateInput->workflowExecution->getID(),
            'workflowRunId'     => $input->updateInput->workflowExecution->getRunID(),
            'workflowOptions'   => $varCloner->cloneVar($input->workflowStartInput->options),
            'workflowArguments' => $varCloner->cloneVar(iterator_to_array($input->workflowStartInput->arguments->getValues())),
            'workflowHeaders'   => $varCloner->cloneVar(iterator_to_array($input->workflowStartInput->header->getIterator())),
        ];

        /**@phpstan-ignore-next-line */
        return $next($input);
    }

    public function getResult(GetResultInput $input, callable $next): ?ValuesInterface
    {
        $varCloner = new VarCloner();

        $this->getResultWorkflow[] = [
            'workflowType'  => $input->workflowType,
            'workflowId'    => $input->workflowExecution->getID(),
            'workflowRunId' => $input->workflowExecution->getRunID(),
            'timeout'       => $input->timeout,
            'returnType'    => $varCloner->cloneVar($input->type),
        ];

        return $next($input);
    }

    public function query(QueryInput $input, callable $next): ?ValuesInterface
    {
        $varCloner = new VarCloner();

        $this->sendQuery[] = [
            'workflowType'   => $input->workflowType,
            'workflowId'     => $input->workflowExecution->getID(),
            'workflowRunId'  => $input->workflowExecution->getRunID(),
            'query'          => $input->queryType,
            'queryArguments' => $varCloner->cloneVar(iterator_to_array($input->arguments->getValues())),
        ];

        return $next($input);
    }

    public function cancel(CancelInput $input, callable $next): void
    {
        $this->sendCancelWorkflow[] = [
            'workflowId'    => $input->workflowExecution->getID(),
            'workflowRunId' => $input->workflowExecution->getRunID(),
        ];

        $next($input);
    }

    public function terminate(TerminateInput $input, callable $next): void
    {
        $this->sendTerminateWorkflow[] = [
            'workflowId'    => $input->workflowExecution->getID(),
            'workflowRunId' => $input->workflowExecution->getRunID(),
        ];

        $next($input);
    }

    public function describe(DescribeInput $input, callable $next): WorkflowExecutionDescription
    {
        $this->sendDescribe[] = [
            'workflowId'    => $input->workflowExecution->getID(),
            'workflowRunId' => $input->workflowExecution->getRunID(),
            'namespace'     => $input->namespace,
        ];

        /**@phpstan-ignore-next-line */
        return $next($input);
    }


    /**
     * @return array<StartedWorkflow>
     */
    public function getStartedWorkflow(): array
    {
        return $this->startedWorkflow;
    }


    /**
     * @return array<SendSignal>
     */
    public function getSendSignal(): array
    {
        return $this->sendSignal;
    }


    /**
     * @return array<SendUpdate>
     */
    public function getSendUpdate(): array
    {
        return $this->sendUpdate;
    }


    /**
     * @return array<StartedWorkflowWithSignal>
     */
    public function getStartedWorkflowWithSignal(): array
    {
        return $this->startedWorkflowWithSignal;
    }


    /**
     * @return array<StartedWorkflowWithUpdate>
     */
    public function getStartedWorkflowWithUpdate(): array
    {
        return $this->startedWorkflowWithUpdate;
    }


    /**
     * @return array<GetResultWorkflow>
     */
    public function getGetResultWorkflow(): array
    {
        return $this->getResultWorkflow;
    }


    /**
     * @return array<SendQuery>
     */
    public function getSendQuery(): array
    {
        return $this->sendQuery;
    }


    /**
     * @return array<SendCancelWorkflow>
     */
    public function getSendCancelWorkflow(): array
    {
        return $this->sendCancelWorkflow;
    }


    /**
     * @return array<SendTerminateWorkflow>
     */
    public function getSendTerminateWorkflow(): array
    {
        return $this->sendTerminateWorkflow;
    }


    /**
     * @return array<SendDescribe>
     */
    public function getSendDescribe(): array
    {
        return $this->sendDescribe;
    }


    public function reset(): void
    {
        $this->startedWorkflow           = [];
        $this->sendSignal                = [];
        $this->sendUpdate                = [];
        $this->startedWorkflowWithSignal = [];
        $this->startedWorkflowWithUpdate = [];
        $this->getResultWorkflow         = [];
        $this->sendQuery                 = [];
        $this->sendCancelWorkflow        = [];
        $this->sendTerminateWorkflow     = [];
        $this->sendDescribe              = [];
    }

    public function isEmpty(): bool
    {
        $signals = [
            $this->sendQuery,
            $this->sendSignal,
            $this->sendUpdate,
            $this->sendDescribe,
            $this->startedWorkflow,
            $this->getResultWorkflow,
            $this->sendCancelWorkflow,
            $this->sendTerminateWorkflow,
            $this->startedWorkflowWithSignal,
            $this->startedWorkflowWithUpdate,
        ];

        foreach ($signals as $signal) {
            if ($signal != []) {
                return false;
            }
        }

        return true;
    }
}
