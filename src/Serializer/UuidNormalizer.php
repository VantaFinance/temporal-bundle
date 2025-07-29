<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Serializer;

use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface as Denormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface as Normalizer;

final readonly class UuidNormalizer implements Normalizer, Denormalizer
{
    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): UuidInterface
    {
        if (!is_string($data)) {
            throw NotNormalizableValueException::createForUnexpectedDataType(
                sprintf('The data is not a valid "%s" string representation.', $type),
                $data,
                ['string']
            );
        }

        return Uuid::fromString($data);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return is_string($data) && is_a($type, UuidInterface::class, true);
    }


    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        if (!$object instanceof UuidInterface) {
            throw new UnexpectedValueException(sprintf('Allowed type: %s', UuidInterface::class));
        }

        return $object->toString();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof UuidInterface;
    }

    /**
     * @param non-empty-string|null $format
     *
     * @return array<class-string, bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            UuidInterface::class => true,
        ];
    }
}
