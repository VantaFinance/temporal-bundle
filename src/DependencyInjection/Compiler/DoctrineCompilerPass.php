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

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;

use Vanta\Integration\Symfony\Temporal\Finalizer\DoctrinePingConnectionFinalizer;

use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Symfony\Temporal\Interceptor\DoctrineActivityInboundInterceptor;

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
            $finalizerId = sprintf('temporal.doctrine_ping_connection_%s.finalizer', $entityManager);

            $container->register($finalizerId, DoctrinePingConnectionFinalizer::class)
                ->setArguments([
                    new Reference('doctrine'),
                    $entityManager,
                ])
                ->addTag('temporal.finalizer')
            ;

            $interceptorId = sprintf('temporal.doctrine_ping_connection_%s_activity_inbound.interceptor', $entityManager);

            $container->register($interceptorId, DoctrineActivityInboundInterceptor::class)
                ->setArguments([
                    definition(DoctrinePingConnectionFinalizer::class)
                        ->setArguments([
                            new Reference('doctrine'),
                            $entityManager,
                        ]),
                ])
            ;
        }
    }
}
