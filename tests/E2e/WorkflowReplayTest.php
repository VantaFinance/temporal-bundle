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
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Vanta\Integration\Symfony\Temporal\Test\App\Workflow\GreetingWorkflow;
use Vanta\Integration\Symfony\Temporal\UI\Cli\WorkflowReplayCommand;

#[CoversClass(WorkflowReplayCommand::class)]
final class WorkflowReplayTest extends E2eTestCase
{
    public function testReplayFromServer(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(GreetingWorkflow::class);
        $run      = $this->workflowClient->start($workflow, 'Vlad');

        assertSame('real Vlad', $run->getResult('string', 10));

        $command = new CommandTester((new Application(self::bootKernel()))->find('temporal:workflow:replay'));
        $status  = $command->execute([
            'workflow-type' => 'GreetingWorkflow',
            '--workflow-id' => $run->getExecution()->getID(),
            '--run-id'      => $run->getExecution()->getRunID(),
        ]);

        assertStringContainsString('replayed successfully', $command->getDisplay());
        assertSame(Command::SUCCESS, $status);
    }


    public function testInvalidOptions(): void
    {
        $command = new CommandTester((new Application(self::bootKernel()))->find('temporal:workflow:replay'));

        assertSame(Command::INVALID, $command->execute(['workflow-type' => 'GreetingWorkflow']));
        assertStringContainsString('Pass either --history-file or --workflow-id', $command->getDisplay());
    }
}
