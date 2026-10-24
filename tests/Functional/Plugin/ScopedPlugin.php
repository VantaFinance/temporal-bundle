<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\Plugin;

use Temporal\Plugin\AbstractPlugin;
use Vanta\Integration\Symfony\Temporal\Attribute\AsPlugin;

#[AsPlugin(clients: ['foo'], workers: true)]
final class ScopedPlugin extends AbstractPlugin
{
    public function __construct()
    {
        parent::__construct('test.scoped');
    }
}
