<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface as EntityManager;
use Monolog\Logger;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\JsonConverter;
use Temporal\Exception\ExceptionInterceptor;
use Vanta\Integration\Symfony\Temporal\DataCollector\TemporalCollector;
use Vanta\Integration\Symfony\Temporal\DataConverter\SymfonySerializerDataConverter;
use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrineClearEntityManagerFinalizer;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services();

    $services->set('temporal.data_converter', DataConverter::class)
        ->args([
            inline_service(JsonConverter::class),
        ])

        ->set('temporal.exception_interceptor', ExceptionInterceptor::class)
            ->factory([ExceptionInterceptor::class, 'createDefault'])

        ->set('temporal.collector', TemporalCollector::class)
            ->tag('data_collector', ['id' => 'Temporal'])
    ;


    if (InstalledVersions::willBeAvailable('symfony/serializer', Serializer::class)) {
        $services->set('temporal.data_converter', DataConverter::class)
            ->args([
                inline_service(SymfonySerializerDataConverter::class)
                    ->args([
                        service('serializer'),
                    ]),
            ])
        ;
    }

    if (InstalledVersions::willBeAvailable('doctrine/doctrine-bundle', EntityManager::class)) {
        $services->set('temporal.doctrine_clear_entity_manager.finalizer', DoctrineClearEntityManagerFinalizer::class)
            ->args([service('doctrine')])
            ->tag('temporal.finalizer')
        ;
    }

    if (InstalledVersions::willBeAvailable('symfony/monolog-bundle', Logger::class)) {
        $services->set('monolog.logger.temporal')
            ->parent('monolog.logger')
            ->call('withName', ['temporal'], true)
        ;
    }
};
