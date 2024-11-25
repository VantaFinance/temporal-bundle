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
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityAHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityBHandler;
use Vanta\Integration\Symfony\Temporal\Test\Functional\Activity\ActivityCHandler;

final class TestActivityBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->register(ActivityAHandler::class);
        $container->register(ActivityBHandler::class);
        $container->register(ActivityCHandler::class);
    }
}
