<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Sentry\SentryBundle\SentryBundle;
use Sentry\State\HubInterface as Hub;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\exceptionInspectorId;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\referenceLogger;

use Vanta\Integration\Symfony\Temporal\ExceptionInterceptor\SentryExceptionInterceptor;
use Vanta\Integration\Symfony\Temporal\InstalledVersions;

final readonly class SentryCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        if (!InstalledVersions::willBeAvailable('sentry/sentry-symfony', SentryBundle::class, [])) {
            return;
        }

        if (!$container->has(Hub::class)) {
            return;
        }

        $temporalConfig = $container->getParameter('temporal.config');
        $workers        = $temporalConfig['workers'] ?? [];

        foreach ($workers as $name => $worker) {
            $exceptionInspectorId = exceptionInspectorId($name);

            if (!$container->hasDefinition($exceptionInspectorId)) {
                continue;
            }

            $newExceptionInspectorId = sprintf('%s.inner.sentry', $exceptionInspectorId);

            $container->register(sprintf('temporal.sentry_%s.interceptor', $name), SentryExceptionInterceptor::class)
                ->setArguments([
                    new Reference($newExceptionInspectorId),
                    new Reference(Hub::class),
                    referenceLogger(),
                ])
                ->setDecoratedService($exceptionInspectorId, $newExceptionInspectorId)
            ;
        }
    }
}
