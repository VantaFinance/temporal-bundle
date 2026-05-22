<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App;

use Sentry\SentryBundle\SentryBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface as Loader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface as Bundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return array<Bundle>
     */
    public function registerBundles(): array
    {
        return [
            new FrameworkBundle(),
            new TemporalBundle(),
            new SentryBundle(),
        ];
    }

    protected function configureContainer(ContainerConfigurator $container, Loader $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'secret' => 'S0ME_SECRET',
            'test'   => true,
        ]);

        $container->parameters()
            ->set('.container.dumper.inline_factories', true)
        ;

        $container->import('Config/temporal.yaml');

        $container->services()
            ->defaults()
            ->autowire()
            ->autoconfigure()

            ->load(__NAMESPACE__ . '\\', __DIR__ . '/')
            ->exclude([
                __DIR__ . '/*.php',
                __DIR__ . '/Sdk/',
            ])
        ;
    }
}
