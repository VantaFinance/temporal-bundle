<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrinePingConnectionFinalizer;
use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\DoctrinePingConnectionExceptionInterceptor;

final class DoctrineCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        if (!ContainerBuilder::willBeAvailable('doctrine/orm', EntityManager::class, [])) {
            return;
        }

        /** @var array<non-empty-string, non-empty-string> $entityManagers */
        $entityManagers = $container->getParameter('doctrine.entity_managers');
        $finalizerIds   = [];

        foreach ($entityManagers as $entityManager => $id) {
            $finalizerId = sprintf('temporal.doctrine_ping_connection_%s.finalizer', $entityManager);

            $container->register($finalizerId, DoctrinePingConnectionFinalizer::class)
                ->setArguments([
                    new Reference('doctrine'),
                    $entityManager,
                ])
                ->addTag('temporal.finalizer')
            ;

            $finalizerIds[$entityManager] = $finalizerId;
        }


        $temporalConfig     = $container->getParameter('temporal.config');
        $workers            = $temporalConfig['workers'] ?? [];
        $revertFinalizerIds = array_flip($finalizerIds);

        foreach ($workers as $worker) {
            foreach ($worker['finalizers'] as $finalizer) {
                if (!in_array($finalizer, $finalizerIds)) {
                    continue;
                }

                $container->register(sprintf('temporal_doctrine_ping_connection_%s.interceptor', $revertFinalizerIds[$finalizer]), DoctrinePingConnectionExceptionInterceptor::class)
                    ->setArguments([
                        new Reference(sprintf('%s.inner', $worker['exceptionInterceptor'])),
                        new Reference($finalizer),
                    ])
                    ->setDecoratedService($worker['exceptionInterceptor'], 'temporal.exception_interceptor.inner')
                ;
            }
        }
    }
}
