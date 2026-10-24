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
use Vanta\Integration\Symfony\Temporal\Test\App\Workflow\ParentGreetingWorkflow;

#[CoversNothing]
final class ChildWorkflowTest extends E2eTestCase
{
    public function testRealChildWorkflow(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(ParentGreetingWorkflow::class);

        assertSame('parent: real Vlad', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
    }


    public function testMockedChildWorkflow(): void
    {
        if (!class_exists('Temporal\Testing\WorkflowMocker')) {
            self::markTestSkipped('Workflow mocks require temporal/sdk >= 2.18');
        }

        /** @phpstan-ignore method.notFound */
        $this->getWorkflowMocker()->expectCompletion('GreetingWorkflow', 'mocked child');

        $workflow = $this->workflowClient->newWorkflowStub(ParentGreetingWorkflow::class);

        assertSame('parent: mocked child', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
    }
}
