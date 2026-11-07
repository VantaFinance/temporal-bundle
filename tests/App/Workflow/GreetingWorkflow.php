<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App\Workflow;

use Carbon\CarbonInterval;
use Generator;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class GreetingWorkflow
{
    /**
     * @return Generator<mixed, mixed, mixed, string>
     */
    #[WorkflowMethod]
    public function greet(string $name): Generator
    {
        $activity = Workflow::newActivityStub(
            GreetingActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout(CarbonInterval::seconds(5)),
        );

        /** @var string $greeting */
        $greeting = yield $activity->greet($name);

        return $greeting;
    }
}
