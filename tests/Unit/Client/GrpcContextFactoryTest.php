<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Client;

use Carbon\CarbonInterval;
use DomainException;

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertInstanceOf;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertSame;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
use Vanta\Integration\Symfony\Temporal\Client\GrpcContextFactory;

#[CoversClass(GrpcContextFactory::class)]
final class GrpcContextFactoryTest extends TestCase
{
    public function testDefaultsKeepSdkContext(): void
    {
        $default = Context::default();
        $context = GrpcContextFactory::create(['value' => 5, 'format' => 'seconds']);

        assertInstanceOf(Context::class, $context);
        assertSame($default->getMetadata(), $context->getMetadata());
        assertEquals($default->getRetryOptions(), $context->getRetryOptions());

        $retry = $context->getRetryOptions();

        assertSame(0, $retry->maximumAttempts);
        assertNotNull($retry->initialInterval);
        assertSame(500, (int) CarbonInterval::instance($retry->initialInterval)->totalMilliseconds);
    }


    public function testMetadataIsMergedIntoSdkHeaders(): void
    {
        $context = GrpcContextFactory::create(
            ['value' => 5, 'format' => 'seconds'],
            metadata: ['x-team' => ['billing'], 'x-tags' => ['a', 1], 'client-name' => ['custom']],
        );

        assertInstanceOf(Context::class, $context);

        $metadata = $context->getMetadata();

        assertSame(['billing'], $metadata['x-team']);
        assertSame(['a', '1'], $metadata['x-tags']);
        assertSame(['custom'], $metadata['client-name']);
        assertSame(Context::default()->getMetadata()['client-version'], $metadata['client-version']);
    }


    public function testRetryOptionsChangeOnlyConfiguredValues(): void
    {
        $context = GrpcContextFactory::create(['value' => 5, 'format' => 'seconds'], retryOptions: [
            'initialInterval'           => null,
            'maximumInterval'           => '30 seconds',
            'backoffCoefficient'        => 1.5,
            'maximumAttempts'           => null,
            'nonRetryableExceptions'    => [DomainException::class],
            'congestionInitialInterval' => '2 seconds',
            'maximumJitterCoefficient'  => 0.2,
        ]);

        $retry = $context->getRetryOptions();

        assertInstanceOf(RpcRetryOptions::class, $retry);
        assertSame(1.5, $retry->backoffCoefficient);
        assertSame(0, $retry->maximumAttempts);
        assertSame([DomainException::class], $retry->nonRetryableExceptions);
        assertSame(0.2, $retry->maximumJitterCoefficient);
        assertNotNull($retry->maximumInterval);
        assertSame(30, (int) CarbonInterval::instance($retry->maximumInterval)->totalSeconds);
        assertNotNull($retry->congestionInitialInterval);
        assertSame(2, (int) CarbonInterval::instance($retry->congestionInitialInterval)->totalSeconds);
        assertEquals(Context::default()->getRetryOptions()->initialInterval, $retry->initialInterval);
    }
}
