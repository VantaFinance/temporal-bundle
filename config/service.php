<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface as EntityManager;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\DataConverter\JsonConverter;
use Temporal\Exception\ExceptionInterceptor;
use Vanta\Integration\Symfony\Temporal\DataConverter\SymfonySerializerDataConverter;
use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrineClearEntityManagerFinalizer;
use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\SentryExceptionInterceptor;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set('temporal.data_converter', JsonConverter::class)

        ->set('temporal.exception_interceptor', ExceptionInterceptor::class)
            ->factory([ExceptionInterceptor::class, 'createDefault'])
    ;


    if (ContainerBuilder::willBeAvailable('symfony/serializer', Serializer::class, [])){
        $services->set('temporal.data_converter', SymfonySerializerDataConverter::class)
            ->args([
                service('serializer')
            ])
        ;
    }


    if (ContainerBuilder::willBeAvailable('doctrine/orm', EntityManager::class, [])){
        $services->set('temporal.doctrine_clear_entity_manager.finalizer', DoctrineClearEntityManagerFinalizer::class)
            ->args([service('doctrine')])
            ->tag('temporal.finalizer')
        ;
    }


    if (ContainerBuilder::willBeAvailable('sentry/sentry-symfony', SentryBundle::class, [])){
        $services->set('temporal.sentry_interceptor', SentryExceptionInterceptor::class)
            ->decorate('temporal.exception_interceptor')
            ->args([
                service('.inner')
            ])
        ;
    }
};

