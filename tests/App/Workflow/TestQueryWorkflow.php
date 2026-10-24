<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App\Workflow;

use Generator;
use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class TestQueryWorkflow
{
    public function __construct(
        private ?TestQuery $args = null,
    ) {
    }


    #[WorkflowMethod]
    public function start(TestQuery $args): Generator
    {
        $this->args = $args;

        yield null;
    }


    #[QueryMethod]
    public function getArgs(): ?TestQuery
    {
        return $this->args;
    }
}
