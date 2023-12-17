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
use Temporal\Client\ClientOptions;

#[AsCommand('debug:temporal:clients', 'List registered clients')]
final class ClientDebugCommand extends Command
{
    /**
     * @param array<int, array{
     *     id: non-empty-string,
     *     name: non-empty-string,
     *     options: ClientOptions,
     *     dataConverter: non-empty-string,
     *     address: non-empty-string
     * }> $clients
     */
    public function __construct(private readonly array $clients)
    {
        parent::__construct();
    }


    protected function configure(): void
    {
        $this->addArgument('clients', mode: InputArgument::IS_ARRAY | InputArgument::OPTIONAL, description: 'Client names', default: []);
    }

    protected function execute(Input $input, Output $output): int
    {
        $foundClients      = false;
        $interestedClients = $input->getArgument('clients');
        $io                = new SymfonyStyle($input, $output);

        $io->title('Temporal Clients');

        foreach ($this->clients as $client) {
            $rows = [];

            if ($interestedClients != [] && !in_array($client['name'], $interestedClients)) {
                continue;
            }

            $foundClients = true;

            $io->title(sprintf('Client: %s', $client['name']));

            $rows[] = [
                $client['id'],
                $client['address'],
                $client['dataConverter'],
                json_encode($client['options'], JSON_PRETTY_PRINT),
            ];

            $rows[] = new TableSeparator();


            if (!is_array(end($rows))) {
                array_pop($rows);
            }

            $io->table(['Id', 'Address', 'DataConverterId','Options'], $rows);
        }


        if (!$foundClients) {
            $io->note('Not found workers');

            return self::SUCCESS;
        }


        return self::SUCCESS;
    }
}
