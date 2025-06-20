<?php

/**
 * Vanta Cash Bus
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Codeception;

use Codeception\Module\Symfony;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;
use Temporal\Testing\ActivityMocker;
use Temporal\Testing\TestService;

final readonly class TemporalTestingTools
{
    public TestService $testService;
    public ActivityMocker $activityMocker;
    public WorkflowClient $workflowClient;

    public function __construct(private Symfony $symfony)
    {
        $this->testService    = $symfony->kernel->getContainer()->get(TestService::class);
        $this->activityMocker = $symfony->kernel->getContainer()->get(ActivityMocker::class);
        $this->workflowClient = $symfony->kernel->getContainer()->get(WorkflowClient::class);
    }

    public function reset(): self
    {
        return new self($this->symfony);
    }
}
