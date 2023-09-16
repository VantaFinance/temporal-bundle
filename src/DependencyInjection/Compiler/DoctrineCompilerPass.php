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
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\exceptionInspectorId;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\DoctrinePingConnectionExceptionInterceptor;
use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrinePingConnectionFinalizer;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;

final class DoctrineCompilerPass implements CompilerPass
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

        foreach ($workers as $name => $worker) {
            foreach ($worker['finalizers'] as $finalizer) {
                if (!in_array($finalizer, $finalizerIds)) {
                    continue;
                }

                $exceptionInspectorId = exceptionInspectorId($name);

                if (!$container->hasDefinition($exceptionInspectorId)) {
                    continue;
                }

                $doctrinePingInspectorId = sprintf('temporal_doctrine_ping_connection_%s_%s.interceptor', $revertFinalizerIds[$finalizer], $name);
                $newExceptionInspectorId = sprintf('%s.%s', $exceptionInspectorId, $doctrinePingInspectorId);


                $container->register($doctrinePingInspectorId, DoctrinePingConnectionExceptionInterceptor::class)
                    ->setArguments([
                        new Reference($newExceptionInspectorId),
                        new Reference($finalizer),
                        referenceLogger(),
                    ])
                    ->setDecoratedService($exceptionInspectorId, $newExceptionInspectorId)
                ;
            }
        }
    }
}
