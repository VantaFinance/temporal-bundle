<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Temporal\Testing\TestService;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;

/**
 * @phpstan-import-type RawConfiguration from Configuration
 */
final class TestServiceCompilerPass implements CompilerPass
{
    public function process(ContainerBuilder $container): void
    {
        /** @var RawConfiguration $config */
        $config = $container->getParameter('temporal.config');

        if (!$config['pool']['testing']['enabled']) {
            return;
        }

        if (!in_array($config['pool']['testing']['defaultTestService'], array_keys($config['pool']['testing']['testServices']))) {
            throw new InvalidArgumentException(sprintf('No default TestService "%s" configured', $config['pool']['testing']['defaultTestService']));
        }

        foreach ($config['pool']['testing']['testServices'] as $name => ['address' => $address]) {
            $id = sprintf('temporal.testing.%s.testService', $name);

            $container->register($id, TestService::class)
                ->setFactory([TestService::class, 'create'])
                ->setPublic(true)
                ->setArguments([
                    $address,
                ])
            ;

            if ($config['pool']['testing']['defaultTestService'] == $name) {
                $container->setAlias(TestService::class, $id);
            }


            $container->registerAliasForArgument($id, TestService::class, sprintf('%sTestService', $name));
        }
    }
}
