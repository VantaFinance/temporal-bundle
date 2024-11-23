<?php
/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2024, The Vanta
 */

declare(strict_types=1);

namespace Unit\DataConverter;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNull;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\DataConverter\Type;
use Vanta\Integration\Symfony\Temporal\DataConverter\SymfonySerializerDataConverter;

#[CoversClass(SymfonySerializerDataConverter::class)]
final class SymfonySerializerDataConverterTest extends TestCase
{
    public function testFromPayloadNull(): void
    {
        $converter = new SymfonySerializerDataConverter(
            new class() implements Serializer {
                public function serialize(mixed $data, string $format, array $context = []): string
                {
                    return 'null';
                }

                public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
                {
                    return null;
                }
            }
        );

        $payload = $converter->toPayload(null);

        assertNull($converter->fromPayload($payload, new Type(Type::TYPE_STRING, allowsNull: true)));
    }


    public function testFromPayloadObject(): void
    {
        $request   = new FooRequest('test', false);
        $converter = new SymfonySerializerDataConverter(
            new class($request) implements Serializer {
                public function __construct(
                    private readonly FooRequest $request,
                ) {
                }

                public function serialize(mixed $data, string $format, array $context = []): string
                {
                    return "null";
                }

                public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
                {
                    assertEquals(FooRequest::class, $type);

                    return $this->request;
                }
            }
        );

        $payload = $converter->toPayload($request);

        assertInstanceOf(
            FooRequest::class,
            $converter->fromPayload($payload, new Type(Type::TYPE_OBJECT))
        );
    }
}



final readonly class FooRequest
{
    public function __construct(
        public string $name,
        public bool $test
    ) {
    }
}
