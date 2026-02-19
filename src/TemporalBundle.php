<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\DoctrineCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\ScheduleClientCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\SentryCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler\WorkflowCompilerPass;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\TemporalExtension;
use function dirname;

final class TemporalBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new WorkflowCompilerPass());
        $container->addCompilerPass(new ClientCompilerPass());
        $container->addCompilerPass(new DoctrineCompilerPass());
        $container->addCompilerPass(new SentryCompilerPass());
        $container->addCompilerPass(new ScheduleClientCompilerPass());
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
