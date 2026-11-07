<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App\Workflow;

use Generator;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class ParentGreetingWorkflow
{
    /**
     * @return Generator<mixed, mixed, mixed, string>
     */
    #[WorkflowMethod]
    public function greet(string $name): Generator
    {
        // Untyped: GreetingWorkflow::greet() declares Generator, the result type is string
        /** @var string $greeting */
        $greeting = yield Workflow::newUntypedChildWorkflowStub('GreetingWorkflow')->execute([$name], 'string'); // @phpstan-ignore argument.type

        return 'parent: ' . $greeting;
    }
}
