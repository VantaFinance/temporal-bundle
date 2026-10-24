<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Client;

use DateInterval;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ContextInterface;
use Throwable;

/**
 * @internal
 */
final class GrpcContextFactory
{
    /**
     * @param array{value: int, format: string} $timeout
     * @param array<string, mixed>              $options
     * @param array<string, list<scalar>>       $metadata
     * @param array{
     *   initialInterval?: ?string,
     *   maximumInterval?: ?string,
     *   backoffCoefficient?: ?float,
     *   maximumAttempts?: ?int<0, max>,
     *   nonRetryableExceptions?: list<class-string<Throwable>>,
     *   congestionInitialInterval?: ?string,
     *   maximumJitterCoefficient?: ?float,
     * } $retryOptions
     */
    public static function create(array $timeout, array $options = [], array $metadata = [], array $retryOptions = []): ContextInterface
    {
        $context = Context::default()->withTimeout($timeout['value'], $timeout['format']);

        if ($options != []) {
            $context = $context->withOptions($options);
        }

        if ($metadata != []) {
            $headers = array_map(static fn (array $values): array => array_map(strval(...), $values), $metadata);

            // withMetadata() replaces the SDK headers
            $context = $context->withMetadata([...$context->getMetadata(), ...$headers]);
        }

        $retry   = RpcRetryOptions::fromRetryOptions($context->getRetryOptions());
        $changed = false;

        foreach (['initialInterval', 'maximumInterval', 'congestionInitialInterval'] as $key) {
            $value = $retryOptions[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $retry = match ($key) {
                'initialInterval'           => $retry->withInitialInterval(DateInterval::createFromDateString($value)),
                'maximumInterval'           => $retry->withMaximumInterval(DateInterval::createFromDateString($value)),
                'congestionInitialInterval' => $retry->withCongestionInitialInterval(DateInterval::createFromDateString($value)),
            };

            $changed = true;
        }

        if (($retryOptions['backoffCoefficient'] ?? null) !== null) {
            $retry   = $retry->withBackoffCoefficient($retryOptions['backoffCoefficient']);
            $changed = true;
        }

        if (($retryOptions['maximumAttempts'] ?? null) !== null) {
            $retry   = $retry->withMaximumAttempts($retryOptions['maximumAttempts']);
            $changed = true;
        }

        if (($retryOptions['maximumJitterCoefficient'] ?? null) !== null) {
            $retry   = $retry->withMaximumJitterCoefficient($retryOptions['maximumJitterCoefficient']);
            $changed = true;
        }

        if (($retryOptions['nonRetryableExceptions'] ?? []) != []) {
            $retry   = $retry->withNonRetryableExceptions($retryOptions['nonRetryableExceptions']);
            $changed = true;
        }

        if (!$changed) {
            return $context;
        }

        return $context->withRetryOptions($retry);
    }
}
