<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App;

require __DIR__ . '/../../vendor/autoload_runtime.php';

use Sentry\SentryBundle\SentryBundle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface as Loader;
use Symfony\Component\Console\Input\InputInterface as Input;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface as Bundle;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

return static function (Input $input, array $context): object {
    $kernel = new class($context['APP_ENV'], (bool) $context['APP_DEBUG']) extends BaseKernel {
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
    };

    if (array_key_exists('RR_MODE', $context) && $context['RR_MODE'] == 'temporal') {
        return $kernel;
    }

    return new Application($kernel);
};
