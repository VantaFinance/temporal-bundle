<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\App\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Greeting.')]
final class GreetingActivity
{
    #[ActivityMethod]
    public function greet(string $name): string
    {
        return 'real ' . $name;
    }
}
