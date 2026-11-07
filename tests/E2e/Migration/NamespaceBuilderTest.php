<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\E2e\Migration;

use DateInterval;

use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use Temporal\Api\Enums\V1\NamespaceState;
use Temporal\Api\PBNamespace\V1\UpdateNamespaceInfo;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceRequest;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceResponse;
use Temporal\Api\Workflowservice\V1\UpdateNamespaceRequest;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\NamespaceBuilder;

#[CoversClass(NamespaceBuilder::class)]
final class NamespaceBuilderTest extends MigrationServerTestCase
{
    public function testEnsureCreatesNamespaceAndReplayPreservesState(): void
    {
        $name    = $this->namespaceName();
        $rpc     = $this->rpc();
        $builder = (new NamespaceBuilder($rpc, $name))
            ->description('Billing workflows')
            ->ownerEmail('platform@example.com')
            ->data(['team' => 'billing'])
            ->retention(new DateInterval('P7D'));

        $builder->ensure();

        $created = $this->describe($name);

        assertSame(604800, (int) $created->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());
        assertSame('Billing workflows', $created->getNamespaceInfo()?->getDescription());
        assertSame('platform@example.com', $created->getNamespaceInfo()->getOwnerEmail());
        assertSame('billing', $created->getNamespaceInfo()->getData()['team']);

        $builder->ensure();

        $builder->assertApplied();

        assertTrue($rpc->isEnsured($name));
        assertSame($created->serializeToString(), $this->describe($name)->serializeToString());
    }

    public function testRetentionDriftPreservesUnmanagedMetadata(): void
    {
        $name = $this->namespaceName();

        (new NamespaceBuilder($this->rpc(), $name))
            ->description('Preserved description')
            ->ownerEmail('owner@example.com')
            ->data(['unmanaged' => 'keep'])
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $before = $this->describe($name);

        (new NamespaceBuilder($this->rpc(), $name))
            ->retention(new DateInterval('P3D'))
            ->ensure();

        $updated = $this->describe($name);

        assertSame(259200, (int) $updated->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());
        assertSame($before->getNamespaceInfo()?->serializeToString(), $updated->getNamespaceInfo()?->serializeToString());
        assertSame($before->getConfig()?->getHistoryArchivalUri(), $updated->getConfig()?->getHistoryArchivalUri());
    }

    public function testMissingNamespaceRequiresExplicitRetention(): void
    {
        $name = $this->namespaceName();

        try {
            (new NamespaceBuilder($this->rpc(), $name))->ensure();

            self::fail('A missing namespace needs explicit retention.');
        } catch (MigrationException $exception) {
            assertStringContainsString('requires explicit retention', $exception->getMessage());
        }

        $this->expectException(ServiceClientException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->describe($name);
    }

    public function testExistingNamespaceWithoutSettingsIsUntouched(): void
    {
        $name = $this->namespaceName();

        (new NamespaceBuilder($this->rpc(), $name))
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $before = $this->describe($name)->serializeToString();

        (new NamespaceBuilder($this->rpc(), $name))->ensure();

        assertSame($before, $this->describe($name)->serializeToString());
    }

    public function testMetadataDriftPreservesUnmanagedFieldsAndReplayIsNoop(): void
    {
        $name = $this->namespaceName();

        (new NamespaceBuilder($this->rpc(), $name))
            ->description('Old description')
            ->ownerEmail('unmanaged@example.com')
            ->data(['unmanaged' => 'keep', 'team' => 'old'])
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $builder = (new NamespaceBuilder($this->rpc(), $name))
            ->description('New description')
            ->data(['team' => 'billing'])
            ->data(['empty' => '']);

        $builder->ensure();

        $updated = $this->describe($name);
        $info    = $updated->getNamespaceInfo();

        assertNotNull($info);
        assertSame('New description', $info->getDescription());
        assertSame('unmanaged@example.com', $info->getOwnerEmail());

        $data = iterator_to_array($info->getData());

        ksort($data);

        assertSame(['empty' => '', 'team' => 'billing', 'unmanaged' => 'keep'], $data);
        assertSame(86400, (int) $updated->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());

        $builder->ensure();

        assertSame($updated->serializeToString(), $this->describe($name)->serializeToString());
    }

    public function testDeprecatedNamespaceFailsInsteadOfReactivating(): void
    {
        $name = $this->namespaceName();

        (new NamespaceBuilder($this->rpc(), $name))
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $this->rpc()->observe(static function () use ($name): bool {
            try {
                self::$service->UpdateNamespace((new UpdateNamespaceRequest())
                    ->setNamespace($name)
                    ->setUpdateInfo(
                        (new UpdateNamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_DEPRECATED),
                    ));

                return true;
            } catch (ServiceClientException $exception) {
                if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                    throw $exception;
                }

                return false;
            }
        }, 'namespace cache readiness', 30, 1.0);

        try {
            (new NamespaceBuilder($this->rpc(), $name))
                ->retention(new DateInterval('P2D'))
                ->ensure();

            self::fail('Deprecated namespaces must not be reactivated.');
        } catch (MigrationException $exception) {
            assertStringContainsString('not in the registered state', $exception->getMessage());
        }

        assertSame(NamespaceState::NAMESPACE_STATE_DEPRECATED, $this->describe($name)->getNamespaceInfo()?->getState());
        assertSame(86400, (int) $this->describe($name)->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());
    }

    public function testDeleteIsExplicitAndAbsentIsNoop(): void
    {
        $name = $this->namespaceName();

        (new NamespaceBuilder($this->rpc(), $name))
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $rpc     = $this->rpc();
        $builder = new NamespaceBuilder($rpc, $name);

        $builder->delete();

        $rpc->observe(function () use ($name): bool {
            try {
                $this->describe($name);

                return false;
            } catch (ServiceClientException $exception) {
                if ($exception->getCode() !== StatusCode::NOT_FOUND) {
                    throw $exception;
                }

                return true;
            }
        }, 'namespace deletion', 60, 0.4);

        $builder->delete();

        $this->expectException(ServiceClientException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->describe($name);
    }

    private function describe(string $namespace): DescribeNamespaceResponse
    {
        return self::$service->DescribeNamespace((new DescribeNamespaceRequest())->setNamespace($namespace));
    }
}
