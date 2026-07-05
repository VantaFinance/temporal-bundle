<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Testing\Codeception;

require_once __DIR__ . '/../RoadRunner/boostrap.php';

use Codeception\Events;
use Codeception\Extension;
use Temporal\Testing\Environment;
use Throwable;

use function Vanta\Integration\Symfony\Temporal\Testing\RoadRunner\boostrapTesting;

final class IntegrationTestingExtension extends Extension
{
    /**
     * @var array<non-empty-string, non-empty-string>
     */
    public static array $events = [
        Events::TEST_BEFORE => 'testBefore',
        Events::TEST_AFTER  => 'testAfter',
    ];

    private readonly Environment $environment;


    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     */
    public function __construct(array $config, array $options)
    {
        parent::__construct($config, $options);

        $this->environment = Environment::create();

        register_shutdown_function($this->environment->stop(...));
    }

    /**
     * @throws Throwable
     */
    public function testBefore(): void
    {
        boostrapTesting($this->environment);
    }


    public function testAfter(): void
    {
        $this->environment->stop();
    }
}
