<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\UI\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Symfony\Component\Console\Style\SymfonyStyle;
use Temporal\Worker\WorkerInterface as Worker;

#[AsCommand('debug:temporal:activities', 'List registered activities')]
final class ActivityDebugCommand extends Command
{
    /**
     * @param array<non-empty-string, Worker> $workers
     */
    public function __construct(
        private readonly array $workers,
        private readonly array $activitiesWithoutWorkers
    ) {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addArgument('workers', mode: InputArgument::IS_ARRAY | InputArgument::OPTIONAL, description: 'Worker names', default: []);
    }


    protected function execute(Input $input, Output $output): int
    {
        $workers = $input->getArgument('workers');
        $io      = new SymfonyStyle($input, $output);

        $io->title('Temporal Activities');

        foreach ($this->workers as $name => $worker) {
            if ($workers != [] && !in_array($name, $workers)){
                continue;
            }

            $rows = [];

            $io->title(sprintf('Worker: %s', $name));

            foreach ($worker->getActivities() as $activity) {
                if (in_array($activity->getClass()->name, $this->activitiesWithoutWorkers)) {
                    continue;
                }

                $rows[] = [
                    $activity->getID(),
                    $activity->getClass()->name,
                    $activity->isLocalActivity() ? 'Yes' : 'No',
                    $activity->getMethodRetry() ? json_encode($activity->getMethodRetry(), JSON_PRETTY_PRINT) : 'None',
                ];

                $rows[] = new TableSeparator();
            }

            if ($rows == []) {
                $io->note('Not found activities');

                continue;
            }

            if (!is_array(end($rows))) {
                array_pop($rows);
            }

            $io->table(['Id', 'Class','IsLocalActivity', 'Retry Policy'], $rows);
        }



        if ($this->activitiesWithoutWorkers == [] || $workers != []) {
            return self::SUCCESS;
        }


        $io->title('Registered activity at all workers');

        $printedActivities = [];

        foreach ($this->workers as $worker) {
            $rows = [];

            foreach ($worker->getActivities() as $activity) {
                if (!in_array($activity->getClass()->name, $this->activitiesWithoutWorkers)) {
                    continue;
                }

                if (in_array($activity->getClass()->name, $printedActivities)) {
                    continue;
                }


                $rows[] = [
                    $activity->getID(),
                    $activity->getClass()->name,
                    $activity->isLocalActivity() ? 'Yes' : 'No',
                    $activity->getMethodRetry() ? json_encode($activity->getMethodRetry(), JSON_PRETTY_PRINT) : 'None',
                ];

                $rows[]              = new TableSeparator();
                $printedActivities[] = $activity->getClass()->name;
            }

            if ($rows == []) {
                continue;
            }

            if (!is_array(end($rows))) {
                array_pop($rows);
            }


            $io->table(['Id', 'Class','IsLocalActivity', 'Retry Policy'], $rows);
        }


        return self::SUCCESS;
    }
}
