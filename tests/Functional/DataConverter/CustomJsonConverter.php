<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Functional\DataConverter;

use Temporal\Api\Common\V1\Payload;
use Temporal\DataConverter\EncodingKeys;
use Temporal\DataConverter\PayloadConverterInterface;
use Temporal\DataConverter\Type;

final class CustomJsonConverter implements PayloadConverterInterface
{
    public function getEncodingType(): string
    {
        return EncodingKeys::METADATA_ENCODING_JSON;
    }


    public function toPayload($value): Payload
    {
        return (new Payload())
            ->setMetadata([EncodingKeys::METADATA_ENCODING_KEY => $this->getEncodingType()])
            ->setData('"custom"')
        ;
    }


    /**
     * @param Type<bool> $type
     */
    public function fromPayload(Payload $payload, Type $type): string
    {
        return 'custom';
    }
}
