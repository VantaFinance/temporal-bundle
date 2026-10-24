<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\PHPUnit;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Temporal\Client\WorkflowClientInterface as WorkflowClient;
use Temporal\Testing\ActivityMocker;
use Temporal\Testing\TestService;

abstract class TemporalTestCase extends KernelTestCase
{
    protected TestService $testService;
    protected ActivityMocker $activityMocker;
    protected WorkflowClient $workflowClient;

    protected function setUp(): void
    {
        // A failed #[WithTemporalEnvironment] start is only a warning in PHPUnit: fail the test instead
        TemporalEnvironmentExtension::rethrowStartFailure();

        $this->testService    = static::getContainer()->get(TestService::class);
        $this->activityMocker = static::getContainer()->get(ActivityMocker::class);
        $this->workflowClient = static::getContainer()->get(WorkflowClient::class);
    }


    /**
     * @return \Temporal\Testing\WorkflowMocker
     */
    protected function getWorkflowMocker(): object // @phpstan-ignore class.notFound
    {
        return static::getContainer()->get('temporal.testing.workflow_mocker'); // @phpstan-ignore return.type
    }


    /**
     * @return \Temporal\Testing\SearchAttributeMocker
     */
    protected function getSearchAttributeMocker(): object // @phpstan-ignore class.notFound
    {
        return static::getContainer()->get('temporal.testing.search_attribute_mocker'); // @phpstan-ignore return.type
    }
}
