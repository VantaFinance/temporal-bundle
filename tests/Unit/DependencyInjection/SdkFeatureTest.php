<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2026, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\Test\Unit\DependencyInjection;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Temporal\Client\WorkflowClient;
use Temporal\Worker\FeatureFlags;
use Temporal\Worker\WorkerOptions;
use Vanta\Integration\Symfony\Temporal\DependencyInjection\SdkFeature;

#[CoversClass(SdkFeature::class)]
final class SdkFeatureTest extends TestCase
{
    protected function tearDown(): void
    {
        SdkFeature::setHandler(null);
    }


    public function testDetectsInstalledSdk(): void
    {
        assertTrue(SdkFeature::hasClass(WorkerOptions::class));
        assertFalse(SdkFeature::hasClass('Temporal\NotExists'));

        assertTrue(SdkFeature::hasMethod(WorkerOptions::class, 'withTaskQueueActivitiesPerSecond'));
        assertFalse(SdkFeature::hasMethod(WorkerOptions::class, 'withBackoff_coefficient'));
        assertFalse(SdkFeature::hasMethod('Temporal\NotExists', 'withAnything'));

        assertTrue(SdkFeature::hasProperty(FeatureFlags::class, 'warnOnWorkflowUnfinishedHandlers'));
        assertFalse(SdkFeature::hasProperty(FeatureFlags::class, 'notExists'));

        assertTrue(SdkFeature::hasParameter(WorkflowClient::class, 'create', 'serviceClient'));
        assertFalse(SdkFeature::hasParameter(WorkflowClient::class, 'create', 'notExists'));
        assertFalse(SdkFeature::hasParameter(WorkflowClient::class, 'notExists', 'serviceClient'));
    }


    public function testRequirePassesWhenFeatureExists(): void
    {
        SdkFeature::requireClass(WorkerOptions::class, 'temporal.workers.default', '2.16');
        SdkFeature::requireMethod(WorkerOptions::class, 'withIdentity', 'temporal.workers.default.identity', '2.16');
        SdkFeature::requireProperty(FeatureFlags::class, 'workflowDeferredHandlerStart', 'temporal.pool.featureFlags.workflowDeferredHandlerStart', '2.11');
        SdkFeature::requireParameter(WorkflowClient::class, 'create', 'serviceClient', 'temporal.clients.default', '2.16');

        $this->addToAssertionCount(4);
    }


    public function testHandlerSimulatesOldSdk(): void
    {
        $calls = [];

        SdkFeature::setHandler(static function (string $kind, string $class, ?string $member, ?string $parameter) use (&$calls): bool {
            $calls[] = [$kind, $class, $member, $parameter];

            return false;
        });

        assertFalse(SdkFeature::hasClass(WorkerOptions::class));
        assertFalse(SdkFeature::hasMethod(WorkerOptions::class, 'withIdentity'));
        assertFalse(SdkFeature::hasProperty(FeatureFlags::class, 'workflowDeferredHandlerStart'));
        assertFalse(SdkFeature::hasParameter(WorkflowClient::class, 'create', 'logger'));

        self::assertSame([
            ['class', WorkerOptions::class, null, null],
            ['method', WorkerOptions::class, 'withIdentity', null],
            ['property', FeatureFlags::class, 'workflowDeferredHandlerStart', null],
            ['parameter', WorkflowClient::class, 'create', 'logger'],
        ], $calls);
    }


    public function testRequireClassThrows(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.cloudClient" requires temporal/sdk >= 2.19 (Temporal\Client\GRPC\CloudClient not found). Run "composer require temporal/sdk:^2.19" or remove the option.');

        SdkFeature::requireClass('Temporal\Client\GRPC\CloudClient', 'temporal.cloudClient', '2.19');
    }


    public function testRequireMethodThrows(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.workers.default.disablePayloadErrorLimit" requires temporal/sdk >= 2.20 (Temporal\Worker\WorkerOptions::withDisablePayloadErrorLimit() not found).');

        SdkFeature::requireMethod(WorkerOptions::class, 'withDisablePayloadErrorLimit', 'temporal.workers.default.disablePayloadErrorLimit', '2.20');
    }


    public function testRequirePropertyThrows(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.pool.featureFlags.readOnlyWorkflowCallbacks" requires temporal/sdk >= 2.20 (Temporal\Worker\FeatureFlags::$readOnlyWorkflowCallbacks not found).');

        SdkFeature::requireProperty(FeatureFlags::class, 'readOnlyWorkflowCallbacks', 'temporal.pool.featureFlags.readOnlyWorkflowCallbacks', '2.20');
    }


    public function testRequireParameterThrows(): void
    {
        SdkFeature::setHandler(static fn (): bool => false);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Option "temporal.clients.default.logger" requires temporal/sdk >= 2.20 (Temporal\Client\WorkflowClient::create($logger) not found).');

        SdkFeature::requireParameter(WorkflowClient::class, 'create', 'logger', 'temporal.clients.default.logger', '2.20');
    }
}
