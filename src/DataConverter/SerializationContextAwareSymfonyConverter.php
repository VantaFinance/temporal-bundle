<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DataConverter;

use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\PayloadConverterInterface as PayloadConverter;
use Temporal\DataConverter\SerializationContext;
use Temporal\DataConverter\SerializationContextAwareInterface;
use Temporal\DataConverter\Type;

final class SerializationContextAwareSymfonyConverter implements PayloadConverter, SerializationContextAwareInterface
{
    public const CONTEXT_KEY = 'temporal_serialization_context';

    private ?SerializationContext $serializationContext = null;


    public function __construct(
        private readonly Serializer $serializer,
        private readonly PayloadConverter $payloadConverter = new JsonConverter(),
    ) {
    }


    public function getEncodingType(): string
    {
        return EncodingKeys::METADATA_ENCODING_JSON;
    }


    public function toPayload($value): Payload
    {
        return $this->converter()->toPayload($value);
    }


    /**
     * @param Type<bool> $type
     */
    public function fromPayload(Payload $payload, Type $type): mixed
    {
        return $this->converter()->fromPayload($payload, $type);
    }


    public function getSerializationContext(): ?SerializationContext
    {
        return $this->serializationContext;
    }


    public function withSerializationContext(?SerializationContext $context): static
    {
        $clone                       = clone $this;
        $clone->serializationContext = $context;

        return $clone;
    }


    private function converter(): SymfonySerializerDataConverter
    {
        $context = $this->serializationContext === null ? [] : [self::CONTEXT_KEY => $this->serializationContext];

        return new SymfonySerializerDataConverter($this->serializer, $this->payloadConverter, $context);
    }
}
