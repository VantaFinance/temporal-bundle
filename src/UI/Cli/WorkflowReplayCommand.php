<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\UI\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Symfony\Component\Console\Style\SymfonyStyle;
use Temporal\Testing\Replay\Exception\ReplayerException;
use Temporal\Testing\Replay\WorkflowReplayer;
use Temporal\Workflow\WorkflowExecution;

#[AsCommand('temporal:workflow:replay', 'Replays a workflow history against the workflow code of the running RoadRunner workers')]
final class WorkflowReplayCommand extends Command
{
    /**
     * @param non-empty-string $roadrunnerRpc RoadRunner RPC address used when RR_RPC is not set
     */
    public function __construct(
        private readonly string $roadrunnerRpc,
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this
            ->addArgument('workflow-type', InputArgument::REQUIRED, 'Workflow type, e.g. "OrderWorkflow"')
            ->addOption('history-file', null, InputOption::VALUE_REQUIRED, 'JSON history file (Temporal UI / CLI "workflow show --output json")')
            ->addOption('workflow-id', null, InputOption::VALUE_REQUIRED, 'Workflow id: the history is fetched from the Temporal server')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Run id of --workflow-id; the latest run by default')
            ->addOption('last-event-id', null, InputOption::VALUE_REQUIRED, 'Replays the --history-file up to this event id', '0')
            ->setHelp(
                <<<'HELP'
                    The replay runs inside RoadRunner: start RoadRunner with the workers of this application first
                    (the replayer talks to its RPC, "RR_RPC" or "temporal.pool.roadrunnerRPC").

                    A non-deterministic history makes the command fail with exit code 1.

                      <info>%command.full_name% OrderWorkflow --history-file=var/history.json</info>
                      <info>%command.full_name% OrderWorkflow --workflow-id=order-42</info>
                    HELP
            )
        ;
    }


    protected function execute(Input $input, Output $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var non-empty-string $workflowType */
        $workflowType = $input->getArgument('workflow-type');
        /** @var non-empty-string|null $historyFile */
        $historyFile = $input->getOption('history-file') ?: null;
        /** @var non-empty-string|null $workflowId */
        $workflowId = $input->getOption('workflow-id') ?: null;
        /** @var non-empty-string|null $runId */
        $runId = $input->getOption('run-id') ?: null;
        /** @var numeric-string $lastEventId */
        $lastEventId = $input->getOption('last-event-id');

        if (($historyFile === null) === ($workflowId === null)) {
            $io->error('Pass either --history-file or --workflow-id');

            return Command::INVALID;
        }

        if ($historyFile !== null && !is_file($historyFile)) {
            $io->error(sprintf('History file "%s" not found', $historyFile));

            return Command::INVALID;
        }

        // WorkflowReplayer reads the RoadRunner RPC address from the environment
        if (($_SERVER['RR_RPC'] ?? $_ENV['RR_RPC'] ?? '') === '') {
            $_SERVER['RR_RPC'] = $this->roadrunnerRpc;
        }

        try {
            $replayer = new WorkflowReplayer();

            // RoadRunner reads the file from its own working directory
            if ($historyFile !== null) {
                $replayer->replayFromJSON($workflowType, realpath($historyFile) ?: $historyFile, max(0, (int) $lastEventId));
            }

            if ($workflowId !== null) {
                $replayer->replayFromServer($workflowType, new WorkflowExecution($workflowId, $runId));
            }
        } catch (ReplayerException $e) {
            $io->error(sprintf('Replay of "%s" failed: %s', $workflowType, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('The history of "%s" replayed successfully', $workflowType));

        return Command::SUCCESS;
    }
}
