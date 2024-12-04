<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2024, The Vanta
 */

declare(strict_types=1);

namespace Functional;

use Nyholm\BundleTest\TestKernel;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertIsResource;

use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Command\ConfigDumpReferenceCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface as CompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelInterface as Kernel;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\Configuration;
use Vanta\Integration\Symfony\Temporal\TemporalBundle;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, string> $options
     */
    protected static function createKernel(array $options = []): Kernel
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(TemporalBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }


    public function testDumpConfig(): void
    {
        $kernel = self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestBundle(TemporalBundle::class);
            $kernel->addTestConfig(__DIR__ . '/Framework/Config/temporal.yaml');


            $kernel->addTestCompilerPass(new class() implements CompilerPass {
                public function process(ContainerBuilder $container): void
                {
                    $container->getDefinition('console.command.config_dump_reference')
                        ->setPublic(true)
                    ;
                }
            });
        }]);

        $command = $kernel->getContainer()->get('console.command.config_dump_reference');

        assertInstanceOf(ConfigDumpReferenceCommand::class, $command);

        $input = new ArrayInput(['name' => 'temporal']);
        $command->setApplication((new Application($kernel)));
        $memory = fopen('php://memory', 'rw+');

        assertIsResource($memory);

        $codeExit = $command->run($input, new StreamOutput($memory));

        assertEquals(0, $codeExit);
    }
}
