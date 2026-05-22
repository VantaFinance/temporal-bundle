<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload_runtime.php';

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputInterface as Input;
use Vanta\Integration\Symfony\Temporal\Test\App\Kernel;

return static function (Input $input, array $context): object {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

    if (array_key_exists('RR_MODE', $context) && $context['RR_MODE'] == 'temporal') {
        return $kernel;
    }

    return new Application($kernel);
};
