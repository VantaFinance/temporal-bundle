<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use Vanta\Integration\Symfony\Temporal\Migration\Migration;
use Vanta\Integration\Symfony\Temporal\Migration\Scheme;

final class SecondRunnerMigration implements Migration
{
    public function up(Scheme $scheme): void
    {
        MigrationRunnerTest::$calls[] = 'second-up';

        if (MigrationRunnerTest::$fail) {
            $scheme->searchAttributes()->keyword('Unapplied');
        }
    }

    public function down(Scheme $scheme): void
    {
        MigrationRunnerTest::$calls[] = 'second-down';
    }
}
