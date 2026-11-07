<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e;

use function PHPUnit\Framework\assertArrayHasKey;
use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesRequest;
use Temporal\Api\PBNamespace\V1\UpdateNamespaceInfo;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceRequest;
use Temporal\Api\Workflowservice\V1\UpdateNamespaceRequest;
use Temporal\Client\GRPC\OperatorClient;
use Temporal\Client\GRPC\ServiceClient;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRunner;
use Vanta\Integration\Symfony\Temporal\Migration\NamespaceBuilder;
use Vanta\Integration\Symfony\Temporal\Migration\SearchAttributeBuilder;
use Vanta\Integration\Symfony\Temporal\Test\App\MigrationKernel;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalDevServer;

#[CoversClass(MigrationRunner::class)]
#[CoversClass(NamespaceBuilder::class)]
#[CoversClass(SearchAttributeBuilder::class)]
final class MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists('Temporal\\Client\\GRPC\\OperatorClientInterface')) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
    }

    public function testFullReplayThroughProductionKernelOnSqlVisibility(): void
    {
        $directory = sys_get_temp_dir() . '/migration-' . bin2hex(random_bytes(5));
        $namespace = 'migration-' . bin2hex(random_bytes(5));
        $address   = '127.0.0.1:17233';
        $binary    = getenv('TEMPORAL_MIGRATION_BINARY') ?: dirname(__DIR__, 2) . '/temporal';
        $server    = new TemporalDevServer(binary: $binary, address: $address);
        $kernel    = new MigrationKernel($directory, $address, $namespace);

        try {
            $server->start();

            mkdir($directory . '/migrations', 0o777, true);

            $phpNamespace = 'RuntimeMigrations' . str_replace('-', '', basename($directory));
            $rawNamespace = $namespace . '-raw';

            $this->writeMigration($directory, $phpNamespace, '20260101000000', sprintf(<<<'PHP'
                $request = (new RegisterNamespaceRequest())
                    ->setNamespace(%s)
                    ->setWorkflowExecutionRetentionPeriod((new Duration())->setSeconds(86400));

                try {
                    $scheme->serviceClient()->RegisterNamespace($request);
                } catch (ServiceClientException $error) {
                    if ($error->getCode() !== StatusCode::ALREADY_EXISTS) {
                        throw $error;
                    }
                }
                PHP, var_export($rawNamespace, true)));

            $this->writeMigration($directory, $phpNamespace, '20260101000001', sprintf(<<<'PHP'
                $scheme->namespace()
                    ->description('Billing workflows')
                    ->ownerEmail('platform@example.com')
                    ->data(['team' => 'managed', 'empty' => ''])
                    ->retention(new DateInterval('P1D'))
                    ->ensure()
                ;
                $scheme->namespace(%s)
                    ->retention(new DateInterval('P1D'))
                    ->ensure()
                ;
                PHP, var_export($namespace . '-other', true)));

            $this->writeMigration($directory, $phpNamespace, '20260101000002', sprintf(<<<'PHP'
                $scheme->searchAttributes()
                    ->keyword('CustomerId')
                    ->integer('OrderNumber')
                    ->apply()
                ;
                $scheme->searchAttributes(%s)
                    ->keyword('OtherId')
                    ->apply()
                ;
                $scheme->operatorClient();
                $scheme->serviceClient();
                PHP, var_export($namespace . '-other', true)), sprintf(<<<'PHP'
                $scheme->searchAttributes()
                    ->remove('CustomerId')
                    ->remove('OrderNumber')
                    ->apply()
                ;
                $scheme->searchAttributes(%s)
                    ->remove('OtherId')
                    ->apply()
                ;
                PHP, var_export($namespace . '-other', true)));

            $application = new Application($kernel);
            $command     = new CommandTester($application->find('temporal:migrations:migrate'));
            $operator    = OperatorClient::create($address);
            $service     = ServiceClient::create($address);
            $list        = static fn (string $name) => $operator->ListSearchAttributes((new ListSearchAttributesRequest())->setNamespace($name))->getCustomAttributes();

            foreach (['up', 'up', 'down', 'down', 'up'] as $direction) {
                assertSame(0, $command->execute(['direction' => $direction], ['interactive' => false]), $command->getDisplay());

                $attributes = $list($namespace);

                if ($direction === 'up') {
                    assertSame(IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD, $attributes['CustomerId']);
                    assertSame(IndexedValueType::INDEXED_VALUE_TYPE_INT, $attributes['OrderNumber']);
                    assertArrayNotHasKey('OtherId', iterator_to_array($attributes));
                    assertArrayHasKey('OtherId', iterator_to_array($list($namespace . '-other')));
                } else {
                    assertArrayNotHasKey('CustomerId', iterator_to_array($attributes));
                    assertSame($namespace, $service->DescribeNamespace((new DescribeNamespaceRequest())->setNamespace($namespace))->getNamespaceInfo()?->getName());
                }
            }

            assertSame($rawNamespace, $service->DescribeNamespace((new DescribeNamespaceRequest())->setNamespace($rawNamespace))->getNamespaceInfo()?->getName());

            $service->UpdateNamespace((new UpdateNamespaceRequest())->setNamespace($namespace)
                ->setUpdateInfo(
                    (new UpdateNamespaceInfo())->setDescription('preserved description')
                        ->setOwnerEmail('owner@example.com')
                        ->setData(['team' => 'old', 'unmanaged' => 'preserved']),
                ));

            $service->UpdateNamespace((new UpdateNamespaceRequest())->setNamespace($namespace)
                ->setUpdateInfo(
                    (new UpdateNamespaceInfo())->setDescription('')
                        ->setOwnerEmail('')
                        ->setData(['team' => 'billing', 'added' => 'new']),
                ));

            $metadata = $service->DescribeNamespace((new DescribeNamespaceRequest())->setNamespace($namespace))->getNamespaceInfo();

            assertSame('preserved description', $metadata->getDescription());
            assertSame('owner@example.com', $metadata->getOwnerEmail());
            assertSame('billing', $metadata->getData()['team']);
            assertSame('preserved', $metadata->getData()['unmanaged']);
            assertSame('new', $metadata->getData()['added']);

            // A newly added file is discovered by the warmed container. Fail after a real mutation,
            // then replay the complete catalog successfully on a fresh command invocation.
            $failureMarker = $directory . '/allow-replay';

            $this->writeMigration($directory, $phpNamespace, '20260101000003', sprintf(<<<'PHP'
                $scheme->namespace()
                    ->retention(new DateInterval('P2D'))
                    ->ensure()
                ;
                $scheme->searchAttributes()
                    ->keyword('PartialId')
                    ->apply()
                ;

                if (!is_file(%s)) {
                    throw new RuntimeException('injected partial failure');
                }
                PHP, var_export($failureMarker, true)), "\$scheme->searchAttributes()\n    ->remove('PartialId')\n    ->apply()\n;");

            assertSame(1, $command->execute([], ['interactive' => false]));
            assertStringContainsString('Partial effects may remain', $command->getDisplay());

            touch($failureMarker);

            assertSame(0, $command->execute([], ['interactive' => false]), $command->getDisplay());
            assertArrayHasKey('PartialId', iterator_to_array($list($namespace)));

            $state = $service->DescribeNamespace((new DescribeNamespaceRequest())->setNamespace($namespace));

            assertSame('Billing workflows', $state->getNamespaceInfo()?->getDescription());
            assertSame('platform@example.com', $state->getNamespaceInfo()->getOwnerEmail());
            assertSame('managed', $state->getNamespaceInfo()->getData()['team']);
            assertSame('', $state->getNamespaceInfo()->getData()['empty']);
            assertSame('preserved', $state->getNamespaceInfo()->getData()['unmanaged']);
            assertSame('new', $state->getNamespaceInfo()->getData()['added']);
            assertSame(172800, (int) $state->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());

            // Explicit deletion is exercised only in its own final fixture.
            $this->writeMigration($directory, $phpNamespace, '20260101000004', sprintf(<<<'PHP'
                $scheme->namespace(%1$s)
                    ->delete()
                ;
                $scheme->namespace(%1$s)
                    ->delete()
                ;
                PHP, var_export($namespace . '-other', true)));

            assertSame(0, $command->execute([], ['interactive' => false]), $command->getDisplay());
        } finally {
            $kernel->shutdown();

            $server->stop();

            (new Filesystem())->remove($directory);
        }
    }

    private function writeMigration(string $directory, string $namespace, string $id, string $up, string $down = ''): void
    {
        $up       = str_replace("\n", "\n        ", $up);
        $down     = str_replace("\n", "\n        ", $down);
        $contents = <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            use DateInterval;
            use Google\Protobuf\Duration;
            use RuntimeException;
            use Temporal\Api\Workflowservice\V1\RegisterNamespaceRequest;
            use Temporal\Client\GRPC\StatusCode;
            use Temporal\Exception\Client\ServiceClientException;
            use Vanta\Integration\Symfony\Temporal\Migration\Migration;
            use Vanta\Integration\Symfony\Temporal\Migration\Scheme;

            final class Migration{$id} implements Migration
            {
                public function up(Scheme \$scheme): void
                {
                    {$up}
                }

                public function down(Scheme \$scheme): void
                {
                    {$down}
                }
            }
            PHP;

        file_put_contents($directory . '/migrations/Migration' . $id . '.php', $contents);
    }
}
