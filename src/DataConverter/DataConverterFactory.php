<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DataConverter;

use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\DataConverterInterface;
use Temporal\DataConverter\PayloadConverterInterface as PayloadConverter;

/**
 * @internal
 */
final class DataConverterFactory
{
    /**
     * @param list<PayloadConverter> $payloadConverters
     * @param list<PayloadConverter> $converters
     */
    public static function create(array $payloadConverters, array $converters): DataConverterInterface
    {
        $encodings = array_map(static fn (PayloadConverter $converter): string => $converter->getEncodingType(), $payloadConverters);

        return new DataConverter(
            ...$payloadConverters,
            ...array_filter($converters, static fn (PayloadConverter $converter): bool => !in_array($converter->getEncodingType(), $encodings, true)),
        );
    }
}
