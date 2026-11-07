<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\DataConverter;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\Type;
use Temporal\DataConverter\WorkflowSerializationContext;
use Vanta\Integration\Symfony\Temporal\DataConverter\SerializationContextAwareSymfonyConverter;

#[CoversClass(SerializationContextAwareSymfonyConverter::class)]
final class SerializationContextAwareSymfonyConverterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists('Temporal\DataConverter\SerializationContextAwareInterface')) {
            self::markTestSkipped('Serialization context requires temporal/sdk >= 2.20');
        }
    }


    public function testContextIsPassedToSerializer(): void
    {
        $serializer = new class() implements Serializer {
            /**
             * @var list<array<string, mixed>>
             */
            public array $contexts = [];

            public function serialize(mixed $data, string $format, array $context = []): string
            {
                $this->contexts[] = $context;

                return '{}';
            }

            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                $this->contexts[] = $context;

                return new stdClass();
            }
        };

        $context   = new WorkflowSerializationContext('default', 'workflow-id');
        $converter = new SerializationContextAwareSymfonyConverter($serializer);
        $aware     = $converter->withSerializationContext($context);

        assertNotSame($converter, $aware);
        assertNull($converter->getSerializationContext());
        assertSame($context, $aware->getSerializationContext());

        $aware->fromPayload($aware->toPayload(new stdClass()), Type::create(stdClass::class));

        assertSame($context, $serializer->contexts[0][SerializationContextAwareSymfonyConverter::CONTEXT_KEY]);
        assertSame($context, $serializer->contexts[1][SerializationContextAwareSymfonyConverter::CONTEXT_KEY]);
    }


    public function testDataConverterForwardsContext(): void
    {
        $context   = new WorkflowSerializationContext('default', 'workflow-id');
        $converter = (new DataConverter(new SerializationContextAwareSymfonyConverter(new class() implements Serializer {
            public function serialize(mixed $data, string $format, array $context = []): string
            {
                return json_encode($context[SerializationContextAwareSymfonyConverter::CONTEXT_KEY] ?? null, JSON_THROW_ON_ERROR);
            }

            public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
            {
                return $data;
            }
        })))->withSerializationContext($context);

        assertSame('{"namespace":"default","workflowId":"workflow-id"}', $converter->toPayload(new stdClass())->getData());
    }
}
