<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal;

use function dirname;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\DoctrineCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\PluginCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ScheduleClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\SentryCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\TestServiceCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\TemporalExtension;

final class TemporalBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TestServiceCompilerPass());
        $container->addCompilerPass(new WorkflowCompilerPass());
        $container->addCompilerPass(new ClientCompilerPass());
        $container->addCompilerPass(new DoctrineCompilerPass());
        $container->addCompilerPass(new SentryCompilerPass());
        $container->addCompilerPass(new ScheduleClientCompilerPass());
        // After the client / worker passes: it completes their definitions
        $container->addCompilerPass(new PluginCompilerPass());
    }

    protected function getContainerExtensionClass(): string
    {
        return TemporalExtension::class;
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
