<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

final class MigrationKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $directory, private readonly string $address, private readonly string $namespace)
    {
        parent::__construct('migration', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();

        yield new TemporalBundle();
    }

    public function getCacheDir(): string
    {
        return $this->directory . '/cache';
    }

    public function getLogDir(): string
    {
        return $this->directory . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'migration-test', 'test' => true]);

        $container->extension('temporal', [
            'clients'    => ['default' => ['address' => $this->address, 'namespace' => $this->namespace]],
            'migrations' => ['enabled' => true, 'paths' => ['RuntimeMigrations' . str_replace('-', '', basename($this->directory)) => $this->directory . '/migrations']],
        ]);
    }
}
