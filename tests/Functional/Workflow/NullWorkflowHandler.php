<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Workflow;

use Temporal\Workflow\WorkflowInterface as Workflow;

#[Workflow]
final class NullWorkflowHandler implements NullWorkflow
{
}
