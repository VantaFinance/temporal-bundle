<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\Serializer;

use Carbon\CarbonInterval;
use DateInterval;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Serializer\CarbonIntervalNormalizer;

#[CoversClass(CarbonIntervalNormalizer::class)]
final class CarbonIntervalNormalizerTest extends TestCase
{
    public function testDenormalize(): void
    {
        $interval = (new CarbonIntervalNormalizer())->denormalize('P1Y2M3DT4H5M6S', CarbonInterval::class);

        assertSame([1, 2, 3, 4, 5, 6, 0], [
            $interval->years,
            $interval->months,
            $interval->dayzExcludeWeeks,
            $interval->hours,
            $interval->minutes,
            $interval->seconds,
            $interval->microseconds,
        ]);
    }


    public function testNormalizeRoundTrip(): void
    {
        $normalizer = new CarbonIntervalNormalizer();
        $interval   = CarbonInterval::create(0, 0, 0, 1, 2, 30, 15);

        $normalized = $normalizer->normalize($interval);

        assertSame('P0Y0M1DT2H30M15S', $normalized);
        assertSame($interval->totalSeconds, $normalizer->denormalize($normalized, CarbonInterval::class)->totalSeconds);
    }


    public function testMicrosecondsAreNotTreatedAsFraction(): void
    {
        $interval = CarbonInterval::instance(DateInterval::createFromDateString('1 second + 500000 usec'));

        assertSame(1, $interval->seconds);
        assertSame(500000, $interval->microseconds);
    }


    public function testSupports(): void
    {
        $normalizer = new CarbonIntervalNormalizer();

        assertTrue($normalizer->supportsNormalization(CarbonInterval::second()));
        assertFalse($normalizer->supportsNormalization(new DateInterval('PT1S')));
        assertTrue($normalizer->supportsDenormalization('PT1S', CarbonInterval::class));
        assertFalse($normalizer->supportsDenormalization('PT1S', DateInterval::class));
    }
}
