<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use DateInterval;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\NamespaceState;
use Temporal\Api\Operatorservice\V1\DeleteNamespaceResponse;
use Temporal\Api\PBNamespace\V1\NamespaceConfig;
use Temporal\Api\PBNamespace\V1\NamespaceInfo;
use Temporal\Api\Workflowservice\V1\DescribeNamespaceResponse;
use Temporal\Api\Workflowservice\V1\RegisterNamespaceRequest;
use Temporal\Api\Workflowservice\V1\RegisterNamespaceResponse;
use Temporal\Api\Workflowservice\V1\UpdateNamespaceRequest;
use Temporal\Api\Workflowservice\V1\UpdateNamespaceResponse;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Exception\Client\ServiceClientException;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Migration\NamespaceBuilder;

#[CoversClass(NamespaceBuilder::class)]
final class NamespaceBuilderTest extends TestCase
{
    private ServiceClientInterface&MockObject $service;
    private OperatorClientInterface&MockObject $operator;
    private ?DescribeNamespaceResponse $state = null;

    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }

        $this->service  = $this->createMock(ServiceClientInterface::class);
        $this->operator = $this->createMock(OperatorClientInterface::class);

        foreach ([$this->service, $this->operator] as $client) {
            $client->method('getContext')->willReturn(Context::default());
            $client->method('withContext')->willReturnSelf();
        }
        $this->service->method('DescribeNamespace')->willReturnCallback(function (): DescribeNamespaceResponse {
            if ($this->state === null) {
                throw $this->error(StatusCode::NOT_FOUND);
            }

            return $this->state;
        });
    }

    public function testEnsureCreatesAndReplayDoesNotMutate(): void
    {
        $this->service->expects(self::once())->method('RegisterNamespace')->willReturnCallback(function (RegisterNamespaceRequest $request): RegisterNamespaceResponse {
            self::assertSame('billing', $request->getNamespace());
            self::assertSame(604800, (int) $request->getWorkflowExecutionRetentionPeriod()?->getSeconds());
            $this->state = (new DescribeNamespaceResponse())
                ->setNamespaceInfo((new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_REGISTERED))
                ->setConfig((new NamespaceConfig())->setWorkflowExecutionRetentionTtl($request->getWorkflowExecutionRetentionPeriod())->setHistoryArchivalUri('preserved'));

            return new RegisterNamespaceResponse();
        });
        $this->service->expects(self::never())->method('UpdateNamespace');
        $this->operator->expects(self::never())->method('DeleteNamespace');
        $rpc = $this->rpc();

        for ($run = 0; $run < 2; ++$run) {
            (new NamespaceBuilder($rpc, 'billing'))->retention(new DateInterval('P7D'))->ensure();
        }
        self::assertTrue($rpc->isEnsured('billing'));
        self::assertSame('preserved', $this->state?->getConfig()?->getHistoryArchivalUri());
    }

    public function testDriftUpdatesOnlyRetentionAndWaits(): void
    {
        $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo((new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_REGISTERED))->setConfig(new NamespaceConfig());
        $this->service->expects(self::never())->method('RegisterNamespace');
        $this->service->expects(self::once())->method('UpdateNamespace')->willReturnCallback(function (UpdateNamespaceRequest $request): UpdateNamespaceResponse {
            self::assertSame('billing', $request->getNamespace());
            self::assertSame('', $request->getConfig()?->getHistoryArchivalUri());
            self::assertNotNull($this->state);
            $this->state->setConfig($request->getConfig());

            return new UpdateNamespaceResponse();
        });
        (new NamespaceBuilder($this->rpc(), 'billing'))->retention(new DateInterval('P3D'))->ensure();
        self::assertSame(259200, (int) $this->state->getConfig()?->getWorkflowExecutionRetentionTtl()?->getSeconds());
    }

    public function testMissingNamespaceRequiresRetention(): void
    {
        $this->service->expects(self::never())->method('RegisterNamespace');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('requires explicit retention');
        (new NamespaceBuilder($this->rpc(), 'billing'))->ensure();
    }

    public function testExistingNamespaceWithoutRetentionIsUntouched(): void
    {
        $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo((new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_REGISTERED));
        $this->service->expects(self::never())->method('UpdateNamespace');
        (new NamespaceBuilder($this->rpc(), 'billing'))->ensure();
        self::assertNull($this->state->getConfig());
    }

    public function testDeleteIsExplicitAndAbsentIsNoop(): void
    {
        $this->state = new DescribeNamespaceResponse();
        $this->operator->expects(self::once())->method('DeleteNamespace')->willReturnCallback(function (): DeleteNamespaceResponse {
            $this->state = null;

            return new DeleteNamespaceResponse();
        });
        $builder = new NamespaceBuilder($this->rpc(), 'billing');
        $builder->delete();
        $builder->delete();
        self::assertNull($this->state);
    }

    public function testCalendarDependentRetentionRejectedWithoutRpc(): void
    {
        $this->service->expects(self::never())->method('DescribeNamespace');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('positive fixed interval');
        (new NamespaceBuilder($this->rpc(), 'billing'))->retention(new DateInterval('P1M'));
    }

    public function testDeprecatedNamespaceFailsInsteadOfReactivate(): void
    {
        $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo((new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_DEPRECATED));
        $this->service->expects(self::never())->method('UpdateNamespace');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('not in the registered state');
        (new NamespaceBuilder($this->rpc(), 'billing'))->ensure();
    }

    public function testRegistrationRaceRechecksExistingNamespace(): void
    {
        $this->service->expects(self::once())->method('RegisterNamespace')->willReturnCallback(function (): never {
            $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo((new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_REGISTERED));
            throw $this->error(StatusCode::ALREADY_EXISTS);
        });
        $this->service->expects(self::once())->method('UpdateNamespace')->willReturnCallback(function (UpdateNamespaceRequest $request): UpdateNamespaceResponse {
            self::assertNotNull($this->state);
            $this->state->setConfig($request->getConfig());

            return new UpdateNamespaceResponse();
        });
        $rpc = $this->rpc();
        (new NamespaceBuilder($rpc, 'billing'))->retention(new DateInterval('P1D'))->ensure();
        self::assertTrue($rpc->isEnsured('billing'));
    }

    public function testMetadataIsCreatedAndReplayIsNoop(): void
    {
        $this->service->expects(self::once())->method('RegisterNamespace')->willReturnCallback(function (RegisterNamespaceRequest $request): RegisterNamespaceResponse {
            self::assertSame('Billing workflows', $request->getDescription());
            self::assertSame('platform@example.com', $request->getOwnerEmail());
            self::assertSame(['team' => 'billing'], iterator_to_array($request->getData()));
            $this->state = (new DescribeNamespaceResponse())
                ->setNamespaceInfo((new NamespaceInfo())
                    ->setState(NamespaceState::NAMESPACE_STATE_REGISTERED)
                    ->setDescription($request->getDescription())
                    ->setOwnerEmail($request->getOwnerEmail())
                    ->setData($request->getData()))
                ->setConfig((new NamespaceConfig())->setWorkflowExecutionRetentionTtl($request->getWorkflowExecutionRetentionPeriod()));

            return new RegisterNamespaceResponse();
        });
        $this->service->expects(self::never())->method('UpdateNamespace');

        for ($run = 0; $run < 2; ++$run) {
            (new NamespaceBuilder($this->rpc(), 'billing'))
                ->description('Billing workflows')
                ->ownerEmail('platform@example.com')
                ->data(['team' => 'billing'])
                ->retention(new DateInterval('P7D'))
                ->ensure();
        }
    }

    public function testMetadataDriftPreservesUnmanagedFieldsAndReplayIsNoop(): void
    {
        $info = (new NamespaceInfo())
            ->setState(NamespaceState::NAMESPACE_STATE_REGISTERED)
            ->setDescription('Old description')
            ->setOwnerEmail('unmanaged@example.com')
            ->setData(['unmanaged' => 'keep', 'team' => 'old']);
        $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo($info);
        $this->service->expects(self::once())->method('UpdateNamespace')->willReturnCallback(static function (UpdateNamespaceRequest $request) use ($info): UpdateNamespaceResponse {
            self::assertNull($request->getConfig());
            $update = $request->getUpdateInfo();
            self::assertNotNull($update);
            self::assertSame('', $update->getOwnerEmail());
            $info->setDescription($update->getDescription());

            $info->setData(array_replace(iterator_to_array($info->getData()), iterator_to_array($update->getData())));

            return new UpdateNamespaceResponse();
        });

        for ($run = 0; $run < 2; ++$run) {
            (new NamespaceBuilder($this->rpc(), 'billing'))
                ->description('New description')
                ->data(['team' => 'billing'])
                ->data(['empty' => ''])
                ->ensure();
        }

        self::assertSame('unmanaged@example.com', $info->getOwnerEmail());
        self::assertSame(['unmanaged' => 'keep', 'team' => 'billing', 'empty' => ''], iterator_to_array($info->getData()));
    }

    public function testEmptyMetadataIsRejectedBeforeRpc(): void
    {
        $this->service->expects(self::never())->method('DescribeNamespace');

        foreach (['description', 'ownerEmail'] as $method) {
            try {
                (new NamespaceBuilder($this->rpc(), 'billing'))->{$method}('   ');
                self::fail('Empty metadata must be rejected.');
            } catch (MigrationException $exception) {
                self::assertStringContainsString('empty', $exception->getMessage());
            }
        }
    }

    public function testPendingMetadataMustBeEnsured(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('call ensure()');
        (new NamespaceBuilder($this->rpc(), 'billing'))->data(['team' => 'billing'])->assertApplied();
    }

    public function testIgnoredMetadataUpdateFailsVerification(): void
    {
        $this->state = (new DescribeNamespaceResponse())->setNamespaceInfo(
            (new NamespaceInfo())->setState(NamespaceState::NAMESPACE_STATE_REGISTERED),
        );
        $this->service->expects(self::once())->method('UpdateNamespace')->willReturn(new UpdateNamespaceResponse());
        $rpc     = $this->rpc();
        $builder = (new NamespaceBuilder($rpc, 'billing'))->ownerEmail('platform@example.com');

        try {
            $builder->ensure();
            self::fail('An accepted RPC is insufficient without matching observed metadata.');
        } catch (MigrationException $exception) {
            self::assertStringContainsString('Unable to verify', $exception->getMessage());
        }

        self::assertFalse($rpc->isEnsured('billing'));
        $this->expectException(MigrationException::class);
        $builder->assertApplied();
    }

    private function rpc(): MigrationRpcContext
    {
        return new MigrationRpcContext($this->service, $this->operator, sleep: static function (float $seconds): void {
        });
    }

    private function error(int $code): ServiceClientException
    {
        return new ServiceClientException((object) ['code' => $code, 'details' => 'fixture']);
    }
}
