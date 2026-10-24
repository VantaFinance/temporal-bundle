<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e;

use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversNothing;
use Vanta\Integration\Symfony\Temporal\Test\App\Workflow\GreetingWorkflow;

#[CoversNothing]
final class ActivityTest extends E2eTestCase
{
    public function testRealActivity(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(GreetingWorkflow::class);

        assertSame('real Vlad', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
    }

    public function testMockedActivity(): void
    {
        $this->activityMocker->expectCompletion('Greeting.greet', 'mocked');

        $workflow = $this->workflowClient->newWorkflowStub(GreetingWorkflow::class);

        assertSame('mocked', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
    }
}
