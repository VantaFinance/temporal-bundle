<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Activity;

use Temporal\Activity\ActivityInterface as Activity;
use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;

#[AssignWorker('foo')]
#[Activity]
final class AssignedActivityHandler
{
}
