<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManager;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrineClearEntityManagerFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrineInterceptorId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\doctrinePingFinalizerId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\loggingDoctrineOpenTransactionInterceptorId;
use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrineFinalizer;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Temporal\Doctrine\Finalizer\DoctrineClearEntityManagerFinalizer;
use Vanta\Integration\Temporal\Doctrine\Finalizer\DoctrinePingConnectionFinalizer;
use Vanta\Integration\Temporal\Doctrine\Interceptor\DoctrineHandlerThrowsActivityInboundInterceptor;
use Vanta\Integration\Temporal\Doctrine\Interceptor\PsrLoggingDoctrineOpenTransactionInterceptor;

final readonly class DoctrineCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        if (!InstalledVersions::willBeAvailable('doctrine/doctrine-bundle', EntityManager::class, [])) {
            return;
        }

        if (!$container->hasParameter('doctrine.entity_managers')) {
            return;
        }



        /** @var array<non-empty-string, non-empty-string> $entityManagers */
        $entityManagers = $container->getParameter('doctrine.entity_managers');

        foreach ($entityManagers as $entityManager => $id) {
            $finalizerId = doctrinePingFinalizerId($entityManager);

            $container->register($finalizerId, DoctrineFinalizer::class)
                ->setArguments([
                    definition(DoctrinePingConnectionFinalizer::class)
                        ->setArguments([
                            new Reference('doctrine'),
                            $entityManager,
                            referenceLogger(),
                        ]),
                ])
                ->addTag('temporal.finalizer')
            ;


            $interceptorId = doctrineInterceptorId($entityManager);

            $container->register($interceptorId, DoctrineHandlerThrowsActivityInboundInterceptor::class)
                ->setArguments([
                    definition(DoctrinePingConnectionFinalizer::class)
                        ->setArguments([
                            new Reference('doctrine'),
                            $entityManager,
                        ]),
                ])
            ;
        }

        $container->register(doctrineClearEntityManagerFinalizerId(), DoctrineFinalizer::class)
            ->setArguments([
                definition(DoctrineClearEntityManagerFinalizer::class)
                    ->setArguments([
                        new Reference('doctrine'),
                    ]),
            ])
            ->addTag('temporal.finalizer')
        ;



        if (!InstalledVersions::willBeAvailable('symfony/monolog-bundle', MonologBundle::class, [])) {
            return;
        }

        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var array<non-empty-string, non-empty-string> $connections */
        $connections = $container->getParameter('doctrine.connections');


        foreach ($connections as $connectionName => $connectionId) {
            $container->register(loggingDoctrineOpenTransactionInterceptorId($connectionName), PsrLoggingDoctrineOpenTransactionInterceptor::class)
                ->setArguments([
                    referenceLogger(),
                    new Reference($connectionId),
                ])
            ;
        }
    }
}
