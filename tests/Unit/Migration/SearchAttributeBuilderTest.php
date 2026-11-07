<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Migration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\AddSearchAttributesResponse;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesResponse;
use Temporal\Api\Operatorservice\V1\RemoveSearchAttributesRequest;
use Temporal\Api\Operatorservice\V1\RemoveSearchAttributesResponse;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\OperatorClientInterface;
use Temporal\Client\GRPC\ServiceClientInterface;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\MigrationRpcContext;
use Vanta\Integration\Symfony\Temporal\Migration\SearchAttributeBuilder;

#[CoversClass(SearchAttributeBuilder::class)]
#[CoversClass(MigrationRpcContext::class)]
final class SearchAttributeBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(OperatorClientInterface::class)) {
            self::markTestSkipped('Temporal migrations require SDK >= 2.18.');
        }
    }

    public function testReplayAllTypesAndRemoveTwice(): void
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $operator->method('getContext')->willReturn(Context::default());
        $operator->method('withContext')->willReturnSelf();
        $service = $this->createMock(ServiceClientInterface::class);
        $service->method('getContext')->willReturn(Context::default());
        $service->method('withContext')->willReturnSelf();
        $state = [];
        $operator->method('ListSearchAttributes')->willReturnCallback(static function () use (&$state): ListSearchAttributesResponse {
            return (new ListSearchAttributesResponse())->setCustomAttributes($state);
        });
        $operator->expects(self::exactly(7))->method('AddSearchAttributes')->willReturnCallback(static function (AddSearchAttributesRequest $request) use (&$state): AddSearchAttributesResponse {
            self::assertSame('billing', $request->getNamespace());

            foreach ($request->getSearchAttributes() as $name => $type) {
                self::assertIsString($name);
                $state[$name] = $type;
            }

            return new AddSearchAttributesResponse();
        });
        $operator->expects(self::exactly(7))->method('RemoveSearchAttributes')->willReturnCallback(static function (RemoveSearchAttributesRequest $request) use (&$state): RemoveSearchAttributesResponse {
            foreach ($request->getSearchAttributes() as $name) {
                unset($state[$name]);
            }

            return new RemoveSearchAttributesResponse();
        });
        $rpc = new MigrationRpcContext($service, $operator);

        for ($run = 0; $run < 2; ++$run) {
            (new SearchAttributeBuilder($rpc, 'billing'))->keyword('CustomerId')->text('Description')->keywordList('Tags')->integer('Number')->double('Amount')->boolean('Priority')->datetime('Delivery')->apply();
        }
        self::assertCount(7, $state);
        self::assertSame(IndexedValueType::INDEXED_VALUE_TYPE_INT, $state['Number']);

        for ($run = 0; $run < 2; ++$run) {
            $builder = new SearchAttributeBuilder($rpc, 'billing');

            foreach (['CustomerId','Description','Tags','Number','Amount','Priority','Delivery'] as $name) {
                $builder->remove($name);
            }
            $builder->apply();
        }
        self::assertSame([], $state);
    }

    public function testSharedIndexResponseIsRespectedAcrossNamespaces(): void
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $service  = $this->createMock(ServiceClientInterface::class);

        foreach ([$operator, $service] as $client) {
            $client->method('getContext')->willReturn(Context::default());
            $client->method('withContext')->willReturnSelf();
        }
        // Elasticsearch-style transport fixture: every namespace sees the same index map.
        $state = [];
        $operator->method('ListSearchAttributes')->willReturnCallback(static function () use (&$state): ListSearchAttributesResponse {
            return (new ListSearchAttributesResponse())->setCustomAttributes($state);
        });
        $operator->expects(self::once())->method('AddSearchAttributes')->willReturnCallback(static function (AddSearchAttributesRequest $request) use (&$state): AddSearchAttributesResponse {
            self::assertSame('first', $request->getNamespace());
            $state['SharedId'] = IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD;

            return new AddSearchAttributesResponse();
        });
        $operator->expects(self::once())->method('RemoveSearchAttributes')->willReturnCallback(static function (RemoveSearchAttributesRequest $request) use (&$state): RemoveSearchAttributesResponse {
            self::assertSame('second', $request->getNamespace());
            $state = [];

            return new RemoveSearchAttributesResponse();
        });
        $rpc = new MigrationRpcContext($service, $operator);
        (new SearchAttributeBuilder($rpc, 'first'))->keyword('SharedId')->apply();
        (new SearchAttributeBuilder($rpc, 'second'))->keyword('SharedId')->apply();
        (new SearchAttributeBuilder($rpc, 'second'))->remove('SharedId')->apply();
        (new SearchAttributeBuilder($rpc, 'first'))->remove('SharedId')->apply();
        self::assertSame([], $state);
    }

    public function testConflictPreventsEntireBatchMutation(): void
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $operator->method('getContext')->willReturn(Context::default());
        $operator->method('withContext')->willReturnSelf();
        $operator->method('ListSearchAttributes')->willReturn((new ListSearchAttributesResponse())->setCustomAttributes(['Conflict' => IndexedValueType::INDEXED_VALUE_TYPE_INT]));
        $operator->expects(self::never())->method('AddSearchAttributes');
        $service = $this->createMock(ServiceClientInterface::class);
        $service->method('getContext')->willReturn(Context::default());
        $service->method('withContext')->willReturnSelf();
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('has type');
        (new SearchAttributeBuilder(new MigrationRpcContext($service, $operator), 'billing'))->keyword('New')->keyword('Conflict')->apply();
    }

    public function testSuccessfulRpcMustReachRequestedState(): void
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $operator->method('getContext')->willReturn(Context::default());
        $operator->method('withContext')->willReturnSelf();
        $operator->method('ListSearchAttributes')->willReturn(new ListSearchAttributesResponse());
        $operator->expects(self::once())->method('AddSearchAttributes')->willReturn(new AddSearchAttributesResponse());
        $service = $this->createMock(ServiceClientInterface::class);
        $service->method('getContext')->willReturn(Context::default());
        $service->method('withContext')->willReturnSelf();
        $rpc = new MigrationRpcContext($service, $operator, sleep: static function (float $seconds): void {
        });
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Unable to verify');
        (new SearchAttributeBuilder($rpc, 'billing'))->keyword('NotActuallyCreated')->apply();
    }

    public function testUnappliedOperationsFail(): void
    {
        $operator = $this->createMock(OperatorClientInterface::class);
        $operator->method('getContext')->willReturn(Context::default());
        $operator->method('withContext')->willReturnSelf();
        $operator->expects(self::never())->method('ListSearchAttributes');
        $service = $this->createMock(ServiceClientInterface::class);
        $service->method('getContext')->willReturn(Context::default());
        $service->method('withContext')->willReturnSelf();
        $builder = (new SearchAttributeBuilder(new MigrationRpcContext($service, $operator), 'billing'))->keyword('Queued');
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('unapplied operations');
        $builder->assertApplied();
    }
}
