<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e;

use DateTimeImmutable;
use DateTimeZone;

use function PHPUnit\Framework\assertEquals;

use PHPUnit\Framework\Attributes\CoversNothing;
use Vanta\Integration\Symfony\Temporal\Test\App\Workflow\TestQuery;
use Vanta\Integration\Symfony\Temporal\Test\App\Workflow\TestQueryWorkflow;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

#[CoversNothing]
final class WorkflowTest extends TemporalTestCase
{
    public function testStartWorkflow(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(
            TestQueryWorkflow::class
        );

        $args = new TestQuery(
            'Vlad Shashkov',
            new DateTimeImmutable(
                '2026-03-08 12:30:45',
                new DateTimeZone('UTC')
            )
        );

        $this->workflowClient->start($workflow, $args);

        assertEquals($args, $workflow->getArgs());
    }
}
