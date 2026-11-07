<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\UI\Cli;

use Closure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationDiscovery;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;

#[AsCommand('temporal:migrations:migrate', 'Replay every Temporal migration; no execution history is stored')]
final class MigrationMigrateCommand extends Command
{
    /** @param Closure(): MigrationRunner $runner */
    public function __construct(
        private readonly MigrationDiscovery $discovery,
        private readonly Closure $runner,
        private readonly string $client,
        private readonly string $namespace,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('direction', InputArgument::OPTIONAL, 'up or down', 'up');
    }

    protected function execute(Input $input, Output $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $direction = $input->getArgument('direction');

        if ($direction !== 'up' && $direction !== 'down') {
            $io->error('Migration direction must be up or down.');

            return self::INVALID;
        }

        try {
            $catalog = $this->discovery->discover();
            $io->text(sprintf('Replay ALL migrations %s; client: %s; default namespace: %s. No execution history is stored.', $direction, $this->client, $this->namespace));

            foreach ($direction === 'down' ? array_reverse($catalog) : $catalog as $migration) {
                $io->text($migration->id . ' ' . $migration->path);
            }

            if ($catalog === []) {
                $io->success('No migrations found.');

                return self::SUCCESS;
            }

            if ($input->isInteractive() && !$io->confirm('Replay the entire catalog? Partial effects can remain on failure.', false)) {
                $io->text('Cancelled.');

                return self::SUCCESS;
            }
            ($this->runner)()->run($direction, $catalog);
            $io->success(sprintf('Replayed %d migrations %s.', count($catalog), $direction));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
