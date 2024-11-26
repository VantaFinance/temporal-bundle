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

#[AsCommand('debug:temporal:workers', 'List registered workers')]
final class WorkerDebugCommand extends Command
{
    /**
     * @param array<non-empty-string, Worker> $workers
     */
    public function __construct(private readonly array $workers)
    {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addArgument('workers', mode: InputArgument::IS_ARRAY | InputArgument::OPTIONAL, description: 'Worker names', default: []);
    }


    protected function execute(Input $input, Output $output): int
    {
        $rows    = [];
        $workers = $this->workers;
        /** @var list<non-empty-string> $interestedWorkers */
        $interestedWorkers    = $input->getArgument('workers');
        $hasInterestedWorkers = $interestedWorkers != [];
        $io                   = new SymfonyStyle($input, $output);


        if ($hasInterestedWorkers) {
            $workers = array_filter(
                $this->workers,
                static fn (string $key): bool => in_array($key, $interestedWorkers),
                ARRAY_FILTER_USE_KEY
            );
        }

        $io->title('Temporal Workers');

        foreach ($workers as $name => $worker) {
            $rows[] = [$name, json_encode($worker->getOptions(), JSON_PRETTY_PRINT)];
            $rows[] = new TableSeparator();
        }

        if (!is_array(end($rows))) {
            array_pop($rows);
        }

        if ($rows == []) {
            $io->note('Not found workers');

            return self::SUCCESS;
        }

        $io->table(['Name', 'Options'], $rows);

        return self::SUCCESS;
    }
}
