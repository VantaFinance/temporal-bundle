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
use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Symfony\Temporal\Interceptor\SentryActivityInboundInterceptor;
use Vanta\Integration\Symfony\Temporal\Interceptor\SentryWorkflowPanicInterceptor;

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


        $container->register('temporal.sentry_workflow_panic.workflow_interceptor', SentryWorkflowPanicInterceptor::class)
            ->setArguments([
                new Reference(Hub::class),
            ])
            ->addTag('temporal.interceptor')
        ;

        $container->register('temporal.sentry_activity_in_bound.activity_interceptor', SentryActivityInboundInterceptor::class)
            ->setArguments([
                new Reference(Hub::class),
            ])
            ->addTag('temporal.interceptor')
        ;
    }
}
