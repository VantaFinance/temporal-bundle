<?php

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DataConverter;

use Symfony\Component\Serializer\SerializerInterface as Serializer;
use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\PayloadConverterInterface as PayloadConverter;
use Temporal\DataConverter\Type;
use Temporal\Exception\DataConverterException;
use Throwable;

final readonly class SymfonySerializerDataConverter implements PayloadConverter
{
    public function __construct(
        private Serializer $serializer,
        private PayloadConverter $payloadConverter = new JsonConverter(),
    ) {
    }


    public function getEncodingType(): string
    {
        return EncodingKeys::METADATA_ENCODING_JSON;
    }

    public function toPayload($value): ?Payload
    {
        try {
            $data = $this->serializer->serialize($value, 'json');
        } catch (Throwable $e) {
            throw new DataConverterException($e->getMessage(), $e->getCode(), $e);
        }

        $payload = new Payload();
        $payload->setMetadata([EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType()]);
        $payload->setData($data);

        return $payload;
    }

    public function fromPayload(Payload $payload, Type $type): mixed
    {
        if (!$type->isClass()) {
            return $this->payloadConverter->fromPayload($payload, $type);
        }

        try {
            return $this->serializer->deserialize($payload->getData(), $type->getName(), 'json');
        } catch (Throwable $e) {
            throw new DataConverterException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
