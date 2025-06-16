<?php

declare(strict_types=1);
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

namespace Vanta\Integration\Symfony\Temporal\Testing\RoadRunner;

use LogicException;
use Spiral\RoadRunner\Console\GetBinaryCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Temporal\Testing\Environment;

function boostrapTesting(Environment $environment): void
{
    /** @var string|null $rrCommand */
    $rrCommand    = $_ENV['TEMPORAL_TESTING_RR_COMMAND'] ?? null;
    $rrBinaryPath = findRRBinaryPath();

    $downloadRRBinary = static function (): void {
        $command  = new GetBinaryCommand();
        $exitCode = $command->execute(
            new ArrayInput([], new InputDefinition(
                [
                    new InputOption('location'),
                    new InputOption('no-config'),
                    new InputOption('filter'),
                    new InputOption('stability'),
                    new InputOption('os'),
                    new InputOption('arch'),
                    new InputOption('no-interaction'),
                ]
            )),
            new ConsoleOutput()
        );

        if ($exitCode != GetBinaryCommand::SUCCESS) {
            throw new LogicException('Failed download roadrunner binary');
        }
    };


    if ($rrCommand == null && $rrBinaryPath == null) {
        $downloadRRBinary();
    }


    if ($rrBinaryPath != null && $rrCommand == null) {
        if (!isInstalledRR($rrBinaryPath)) {
            $downloadRRBinary();
        }

        $rrCommand = sprintf('%s serve -c .rr.testing.yaml', $rrBinaryPath);
    }


    $environment->startTemporalTestServer();
    $environment->startRoadRunner($rrCommand, envs:  $_ENV);
}



function findRRBinaryPath(): ?string
{
    return (new ExecutableFinder())->find('rr', extraDirs: [getcwd(), '/usr/local/bin/', '/usr/bin/']);
}

function isInstalledRR(string $binaryPath): bool
{
    $process = Process::fromShellCommandline(sprintf('%s -v', $binaryPath));

    $process->run();

    if ($process->isSuccessful() && str_contains($process->getOutput(), 'rr version')) {
        return true;
    }

    return false;
}
