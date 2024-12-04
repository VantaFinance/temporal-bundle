<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DataConverter;

use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer as ObjectNormalizer;
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
    private const INPUT_TYPE = 'symfony.serializer.type';


    public function __construct(
        private Serializer $serializer,
        private PayloadConverter $payloadConverter = new JsonConverter(),
    ) {
    }


    public function getEncodingType(): string
    {
        return EncodingKeys::METADATA_ENCODING_JSON;
    }

    public function toPayload($value): Payload
    {
        $metadata = [
            EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType(),
        ];

        $context = [];

        if (is_object($value)) {
            $metadata[self::INPUT_TYPE] = $value::class;

            if (str_starts_with($value::class, 'Temporal\\')) {
                $context[ObjectNormalizer::PRESERVE_EMPTY_OBJECTS] = true;
            }
        }

        try {
            $data = $this->serializer->serialize($value, 'json', $context);
        } catch (Throwable $e) {
            throw new DataConverterException($e->getMessage(), $e->getCode(), $e);
        }

        $payload = new Payload();
        $payload->setMetadata($metadata);
        $payload->setData($data);

        return $payload;
    }

    public function fromPayload(Payload $payload, Type $type): mixed
    {
        if ("null" == $payload->getData() && $type->allowsNull()) {
            return null;
        }

        /** @var string|null $inputType */
        $inputType = $payload->getMetadata()[self::INPUT_TYPE] ?? null;

        if (!$type->isClass() && $inputType == null) {
            return $this->payloadConverter->fromPayload($payload, $type);
        }

        try {
            return $this->serializer->deserialize($payload->getData(), $inputType ?? $type->getName(), 'json');
        } catch (Throwable $e) {
            throw new DataConverterException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
