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
use Throwable;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationGenerator;

#[AsCommand('temporal:migrations:generate', 'Generate a PHP Temporal migration without contacting the server')]
final class MigrationGenerateCommand extends Command
{
    public function __construct(private readonly MigrationGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Migration description')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Configured PHP namespace (not a Temporal namespace)');
    }

    protected function execute(Input $input, Output $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $name      = $input->getArgument('name');
        $namespace = $input->getOption('namespace');

        if (!is_string($name) || ($namespace !== null && !is_string($namespace))) {
            $io->error('Expected a migration name and an optional PHP namespace.');

            return self::INVALID;
        }

        try {
            $io->success('Created ' . $this->generator->generate($name, $namespace));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
