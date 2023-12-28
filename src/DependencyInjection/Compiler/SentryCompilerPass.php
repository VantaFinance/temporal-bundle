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
use Sentry\Serializer\RepresentationSerializer;
use Sentry\StacktraceBuilder;
use Sentry\State\HubInterface as Hub;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function Vanta\Integration\Symfony\Temporal\DependencyInjection\definition;

use Vanta\Integration\Symfony\Temporal\InstalledVersions;
use Vanta\Integration\Symfony\Temporal\Interceptor\SentryActivityInboundInterceptor;
use Vanta\Integration\Symfony\Temporal\Interceptor\SentryWorkflowOutboundCallsInterceptor;

final readonly class SentryCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        if (!InstalledVersions::willBeAvailable('sentry/sentry-symfony', SentryBundle::class, [])) {
            return;
        }

        if (!$container->has(Hub::class) && !$container->has('sentry.client.options')) {
            return;
        }


        $container->register('temporal.sentry_stack_trace_builder', StacktraceBuilder::class)
            ->setArguments([
                new Reference('sentry.client.options'),
                definition(RepresentationSerializer::class)
                    ->setArguments([
                        new Reference('sentry.client.options'),
                    ]),
            ])
        ;


        $container->register('temporal.sentry_workflow_outbound_calls.interceptor', SentryWorkflowOutboundCallsInterceptor::class)
            ->setArguments([
                new Reference(Hub::class),
                new Reference('temporal.sentry_stack_trace_builder'),
            ])
        ;

        $container->register('temporal.sentry_activity_inbound_interceptor', SentryActivityInboundInterceptor::class)
            ->setArguments([
                new Reference(Hub::class),
                new Reference('temporal.sentry_stack_trace_builder'),
            ])
        ;
    }
}
