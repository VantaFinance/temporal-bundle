<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Bundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\AssignWorkflowHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\AssignWorkflowHandlerV2;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow\NullWorkflowHandler;

final class TestWorkflowBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(NullWorkflowHandler::class);
        $container->register(AssignWorkflowHandler::class);
        $container->register(AssignWorkflowHandlerV2::class);
    }
}
