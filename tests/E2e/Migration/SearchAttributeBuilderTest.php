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

use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

use PHPUnit\Framework\Attributes\CoversClass;
use Temporal\Api\Enums\V1\IndexedValueType;
use Temporal\Api\Operatorservice\V1\ListSearchAttributesRequest;
use Vanta\Integration\Symfony\Temporal\Migration\Exception\MigrationException;
use Vanta\Integration\Symfony\Temporal\Migration\NamespaceBuilder;
use Vanta\Integration\Symfony\Temporal\Migration\Scheme;
use Vanta\Integration\Symfony\Temporal\Migration\SearchAttributeBuilder;

#[CoversClass(SearchAttributeBuilder::class)]
#[CoversClass(Scheme::class)]
final class SearchAttributeBuilderTest extends MigrationServerTestCase
{
    public function testReplayAllTypesAndRemoveTwice(): void
    {
        $namespace = $this->namespaceName();
        $rpc       = $this->rpc();

        (new NamespaceBuilder($rpc, $namespace))
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $expected = [
            'CustomerId'  => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD,
            'Description' => IndexedValueType::INDEXED_VALUE_TYPE_TEXT,
            'Tags'        => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD_LIST,
            'Number'      => IndexedValueType::INDEXED_VALUE_TYPE_INT,
            'Amount'      => IndexedValueType::INDEXED_VALUE_TYPE_DOUBLE,
            'Priority'    => IndexedValueType::INDEXED_VALUE_TYPE_BOOL,
            'Delivery'    => IndexedValueType::INDEXED_VALUE_TYPE_DATETIME,
        ];

        ksort($expected);

        for ($run = 0; $run < 2; ++$run) {
            (new SearchAttributeBuilder($rpc, $namespace))
                ->keyword('CustomerId')
                ->text('Description')
                ->keywordList('Tags')
                ->integer('Number')
                ->double('Amount')
                ->boolean('Priority')
                ->datetime('Delivery')
                ->apply();

            assertSame($expected, $this->attributes($namespace));
        }

        for ($run = 0; $run < 2; ++$run) {
            $builder = new SearchAttributeBuilder($rpc, $namespace);

            foreach (array_keys($expected) as $name) {
                $builder->remove($name);
            }

            $builder->apply();

            assertSame([], $this->attributes($namespace));
        }
    }

    public function testConflictPreventsEntireBatchMutation(): void
    {
        $namespace = $this->namespaceName();
        $rpc       = $this->rpc();

        (new NamespaceBuilder($rpc, $namespace))
            ->retention(new DateInterval('P1D'))
            ->ensure();

        (new SearchAttributeBuilder($rpc, $namespace))
            ->integer('Conflict')
            ->apply();

        try {
            (new SearchAttributeBuilder($rpc, $namespace))
                ->keyword('New')
                ->keyword('Conflict')
                ->apply();

            self::fail('A type conflict must prevent every mutation in the batch.');
        } catch (MigrationException $exception) {
            assertStringContainsString('has type', $exception->getMessage());
        }

        assertSame(['Conflict' => IndexedValueType::INDEXED_VALUE_TYPE_INT], $this->attributes($namespace));
    }

    public function testSchemeChildrenKeepIndependentNamespaces(): void
    {
        $first  = $this->namespaceName();
        $second = $this->namespaceName();
        $scheme = new Scheme($this->rpc(), $first);

        $scheme
            ->namespace()
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $scheme
            ->namespace($second)
            ->retention(new DateInterval('P1D'))
            ->ensure();

        $firstBuilder = $scheme
            ->searchAttributes()
            ->keyword('CustomerId');
        $secondBuilder = $scheme
            ->searchAttributes($second)
            ->integer('CustomerId');

        $firstBuilder->apply();

        $secondBuilder->apply();

        assertSame(['CustomerId' => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD], $this->attributes($first));
        assertSame(['CustomerId' => IndexedValueType::INDEXED_VALUE_TYPE_INT], $this->attributes($second));

        $scheme
            ->searchAttributes($second)
            ->remove('CustomerId')
            ->apply();

        assertSame([], $this->attributes($second));
        assertSame(['CustomerId' => IndexedValueType::INDEXED_VALUE_TYPE_KEYWORD], $this->attributes($first));

        $scheme->assertApplied();
    }

    /** @return array<string, int> */
    private function attributes(string $namespace): array
    {
        $attributes = [];

        foreach (self::$operator->ListSearchAttributes((new ListSearchAttributesRequest())->setNamespace($namespace))->getCustomAttributes() as $name => $type) {
            assertIsString($name);
            assertIsInt($type);

            $attributes[$name] = $type;
        }

        ksort($attributes);

        return $attributes;
    }
}
