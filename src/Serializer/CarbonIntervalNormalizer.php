<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2025, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Serializer;

use Carbon\CarbonInterval;
use Exception;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Normalizer\DateIntervalNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface as Denormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface as Normalizer;

final readonly class CarbonIntervalNormalizer implements Normalizer, Denormalizer
{
    public function __construct(
        private DateIntervalNormalizer $normalizer = new DateIntervalNormalizer(),
    ) {
    }


    /**
     * @throws Exception
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): CarbonInterval
    {
        $interval = $this->normalizer->denormalize($data, $type, $format, $context);

        return new CarbonInterval(
            years: $interval->y,
            months: $interval->m,
            days: $interval->d,
            hours: $interval->h,
            minutes: $interval->i,
            seconds: $interval->s,
            microseconds: $interval->f,
        );
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $type == CarbonInterval::class;
    }


    /**
     * @param non-empty-string|null $format
     *
     * @return array<class-string, true>
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            CarbonInterval::class => true,
        ];
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        if (!$object instanceof CarbonInterval) {
            throw new UnexpectedValueException(sprintf('Allowed type: %s', CarbonInterval::class));
        }

        return $this->normalizer->normalize($object->toDateInterval(), $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof CarbonInterval;
    }
}
