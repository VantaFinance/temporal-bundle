<?php

/**
 * Temporal Bundle
 *
 * @author Vlad Shashkov <v.shashkov@pos-credit.ru>
 * @copyright Copyright (c) 2023, The Vanta
 */

declare(strict_types=1);

namespace Vanta\Integration\Symfony\Temporal\DependencyInjection;

use DateInterval;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface as Container;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Client\ClientOptions;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ContextInterface;
use Temporal\Client\GRPC\ServiceClient as GrpcServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface as ServiceClient;
use Temporal\Interceptor\GrpcClientInterceptor;
use Temporal\Interceptor\SimplePipelineProvider;
use Throwable;
use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;

/**
 * @internal
 *
 * @param class-string|null                  $class
 * @param array<int|non-empty-string,mixed>  $arguments
 */
function definition(?string $class = null, array $arguments = []): Definition
{
    return new Definition($class, $arguments);
}

/**
 * @internal
 */
function dateIntervalDefinition(string $interval): Definition
{
    return definition(DateInterval::class)
        ->setFactory([DateInterval::class, 'createFromDateString'])
        ->setArguments([$interval])
    ;
}


/**
 * @internal
 *
 * @param non-empty-string $id
 */
function reference(string $id, int $invalidBehavior = Container::EXCEPTION_ON_INVALID_REFERENCE): Reference
{
    return new Reference($id, $invalidBehavior);
}

/**
 * @internal
 */
function isMonologEnabled(ContainerBuilder $container): bool
{
    if (!$container->hasParameter('kernel.bundles')) {
        return false;
    }

    $bundles = $container->getParameter('kernel.bundles');

    return is_array($bundles) && in_array(MonologBundle::class, $bundles, true);
}


/**
 * @internal
 */
function referenceLogger(): Reference
{
    return reference('monolog.logger.temporal', Container::IGNORE_ON_INVALID_REFERENCE);
}


/**
 * @internal
 *
 * @param array{
 *  address: non-empty-string,
 *  envConfig: array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string},
 *  apiKey: ?non-empty-string,
 *  tls: array{
 *    enabled: ?bool,
 *    rootCa: ?non-empty-string,
 *    clientKey: ?non-empty-string,
 *    clientPem: ?non-empty-string,
 *    serverName: ?non-empty-string,
 *  },
 *  grpcContext: array{
 *    timeout: array{value: int, format: string},
 *    options: array<string, mixed>,
 *    metadata: array<string, list<scalar>>,
 *    retryOptions: array{
 *      initialInterval: ?string,
 *      maximumInterval: ?string,
 *      backoffCoefficient: ?float,
 *      maximumAttempts: ?int<0, max>,
 *      nonRetryableExceptions: list<class-string<Throwable>>,
 *      congestionInitialInterval: ?string,
 *      maximumJitterCoefficient: ?float,
 *    },
 *  },
 * } $client
 */
function grpcClient(array $client, string $path): Definition
{
    if ($client['envConfig']['enabled']) {
        assertEnvConfigSupported($client['envConfig'], $path);

        return definition(ServiceClient::class)
            ->setFactory([envConfigProfile($client['envConfig']), 'toServiceClient'])
            ->addMethodCall('withContext', [grpcContext($client['grpcContext'])], true)
        ;
    }

    $tls = $client['tls'];

    // An API key is never sent without TLS unless tls.enabled is false
    $useTls = $tls['enabled'] ?? ($client['apiKey'] !== null || $tls['rootCa'] !== null || $tls['clientKey'] !== null || $tls['clientPem'] !== null || $tls['serverName'] !== null);

    $serviceClient = definition(ServiceClient::class, [$client['address']])
        ->setFactory([GrpcServiceClient::class, 'create'])
    ;

    if ($useTls) {
        $serviceClient = definition(ServiceClient::class, [$client['address'], $tls['rootCa'], $tls['clientKey'], $tls['clientPem'], $tls['serverName']])
            ->setFactory([GrpcServiceClient::class, 'createSSL'])
        ;
    }

    $apiKey = $client['apiKey'];

    // "@service_id": a \Stringable service, so that the key can be rotated without a container rebuild
    if ($apiKey !== null) {
        $serviceClient->addMethodCall('withAuthKey', [str_starts_with($apiKey, '@') ? new Reference(substr($apiKey, 1)) : $apiKey], true);
    }

    return $serviceClient->addMethodCall('withContext', [grpcContext($client['grpcContext'])], true);
}


/**
 * @internal
 *
 * @param array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string} $envConfig
 */
function envConfigProfile(array $envConfig): Definition
{
    return (new Definition('Temporal\Common\EnvConfig\Client\ConfigProfile'))
        ->setFactory(['Temporal\Common\EnvConfig\ConfigClient', 'load'])
        ->setArguments([$envConfig['profile'], $envConfig['configFile']])
    ;
}


/**
 * @internal
 *
 * @param list<non-empty-string> $interceptors
 */
function grpcInterceptorPipeline(Definition $serviceClient, array $interceptors): void
{
    if ($interceptors == []) {
        return;
    }

    // Added last: the pipeline is bound to the client instance it is created for
    $serviceClient->addMethodCall('withInterceptorPipeline', [
        definition('Temporal\Internal\Interceptor\Pipeline')
            ->setFactory([definition(SimplePipelineProvider::class, [array_map(reference(...), $interceptors)]), 'getPipeline'])
            ->setArguments([GrpcClientInterceptor::class]),
    ], true);
}


/**
 * @internal
 *
 * @param array{
 *  namespace: ?non-empty-string,
 *  envConfig: array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string},
 *  identity?: non-empty-string,
 *  queryRejectionCondition?: int,
 *  payloadLimits?: array{enabled: bool, payloadSizeWarning: ?positive-int, memoSizeWarning: ?positive-int},
 * } $client
 */
function clientOptions(array $client, string $path): Definition
{
    $options = definition(ClientOptions::class);

    if ($client['envConfig']['enabled']) {
        $options->setFactory([envConfigProfile($client['envConfig']), 'toClientOptions']);
    }

    if ($client['namespace'] !== null) {
        $options->addMethodCall('withNamespace', [$client['namespace']], true);
    }

    if ($client['identity'] ?? false) {
        $options->addMethodCall('withIdentity', [$client['identity']], true);
    }

    if (array_key_exists('queryRejectionCondition', $client)) {
        $options->addMethodCall('withQueryRejectionCondition', [$client['queryRejectionCondition']], true);
    }

    if (isset($client['payloadLimits'])) {
        $options->addMethodCall('withPayloadLimits', [payloadLimits($client['payloadLimits'], $path . '.payloadLimits')], true);
    }

    return $options;
}


/**
 * @internal
 *
 * @param array{enabled: bool, payloadSizeWarning: ?positive-int, memoSizeWarning: ?positive-int} $limits
 */
function payloadLimits(array $limits, string $path): Definition
{
    $class = 'Temporal\Common\PayloadLimitOptions';

    SdkFeature::requireClass($class, $path, '2.20');

    if (!$limits['enabled']) {
        return (new Definition($class))->setFactory([$class, 'disabled']);
    }

    $options = (new Definition($class))->setFactory([$class, 'new']);

    if ($limits['payloadSizeWarning'] !== null) {
        $options->addMethodCall('withPayloadSizeWarning', [$limits['payloadSizeWarning']], true);
    }

    if ($limits['memoSizeWarning'] !== null) {
        $options->addMethodCall('withMemoSizeWarning', [$limits['memoSizeWarning']], true);
    }

    return $options;
}


/**
 * @internal
 *
 * @param non-empty-string $globalLogger
 */
function globalLogger(ContainerBuilder $container, string $globalLogger): ?Reference
{
    if ($globalLogger == 'monolog.logger.temporal' && !isMonologEnabled($container)) {
        return null;
    }

    return reference($globalLogger);
}


/**
 * @internal
 *
 * @param class-string          $clientClass
 * @param non-empty-string|null $logger
 * @param non-empty-string      $globalLogger
 */
function clientLogger(ContainerBuilder $container, Definition $client, string $clientClass, ?string $logger, string $globalLogger, string $path): void
{
    if ($logger !== null) {
        SdkFeature::requireParameter($clientClass, 'create', 'logger', $path . '.logger', '2.20');

        $client->setArgument('$logger', reference($logger));

        return;
    }

    $reference = globalLogger($container, $globalLogger);

    if ($reference !== null && SdkFeature::hasParameter($clientClass, 'create', 'logger')) {
        $client->setArgument('$logger', $reference);
    }
}


/**
 * @internal
 *
 * @param array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string} $envConfig
 */
function assertEnvConfigSupported(array $envConfig, string $path): void
{
    SdkFeature::requireClass('Temporal\Common\EnvConfig\ConfigClient', $path . '.envConfig', '2.17.0');

    if ($envConfig['configFile'] !== null && !SdkFeature::hasClass('Internal\Toml\Toml')) {
        throw new InvalidConfigurationException(sprintf('Option "%s.envConfig.configFile" needs a TOML parser: run "composer require internal/toml".', $path));
    }
}


/**
 * @internal
 *
 * @param array{
 *  envConfig: array{enabled: bool, profile: ?non-empty-string, configFile: ?non-empty-string},
 *  apiKey: ?non-empty-string,
 *  tls: array{enabled: ?bool, rootCa: ?non-empty-string, clientKey: ?non-empty-string, clientPem: ?non-empty-string, serverName: ?non-empty-string},
 * } $client
 */
function connectionSummary(array $client): string
{
    if ($client['envConfig']['enabled']) {
        return sprintf(
            'envConfig: profile %s, %s',
            $client['envConfig']['profile'] ?? 'TEMPORAL_PROFILE / default',
            $client['envConfig']['configFile'] !== null ? 'TOML configured' : 'TOML from TEMPORAL_CONFIG_FILE / default path',
        );
    }

    $tls = $client['tls'];

    $parts = [sprintf('TLS: %s', match ($tls['enabled']) {
        true    => 'on',
        false   => 'off',
        default => 'auto',
    })];

    foreach (['rootCa' => 'root CA', 'clientKey' => 'client certificate', 'serverName' => 'server name'] as $key => $label) {
        if ($tls[$key] !== null) {
            $parts[] = $label;
        }
    }

    $parts[] = $client['apiKey'] !== null ? 'API key: set' : 'API key: none';

    return implode(', ', $parts);
}


/**
 * @internal
 *
 * @param array{
 *  timeout: array{value: int, format: string},
 *  options: array<string, mixed>,
 *  metadata: array<string, list<scalar>>,
 *  retryOptions: array{
 *    initialInterval: ?string,
 *    maximumInterval: ?string,
 *    backoffCoefficient: ?float,
 *    maximumAttempts: ?int<0, max>,
 *    nonRetryableExceptions: list<class-string<Throwable>>,
 *    congestionInitialInterval: ?string,
 *    maximumJitterCoefficient: ?float,
 *  },
 * } $rawContext
 */
function grpcContext(array $rawContext): Definition
{
    $context = definition(ContextInterface::class)
        ->setFactory([Context::class, 'default'])
        ->addMethodCall('withTimeout', [$rawContext['timeout']['value'], $rawContext['timeout']['format']], true)
    ;

    if ($rawContext['options'] != []) {
        $context->addMethodCall('withOptions', [$rawContext['options']], true);
    }

    // withMetadata() replaces the SDK headers (client-name, client-version)
    if ($rawContext['metadata'] != []) {
        $context->addMethodCall('withMetadata', [[
            ...Context::default()->getMetadata(),
            ...array_map(static fn (array $values): array => array_map(strval(...), $values), $rawContext['metadata']),
        ]], true);
    }

    // Starts from the SDK retry options: 500 ms initial interval, unlimited attempts
    $retryOptions = definition(RpcRetryOptions::class)
        ->setFactory([definition(Context::class)->setFactory([Context::class, 'default']), 'getRetryOptions'])
    ;

    foreach ($rawContext['retryOptions'] as $option => $value) {
        if ($value === null || $value === []) {
            continue;
        }

        if (is_string($value)) {
            $value = dateIntervalDefinition($value);
        }

        $retryOptions->addMethodCall(sprintf('with%s', ucfirst($option)), [$value], true);
    }

    if ($retryOptions->getMethodCalls() != []) {
        $context->addMethodCall('withRetryOptions', [$retryOptions], true);
    }

    return $context;
}


/**
 * @internal
 *
 * @return class-string|null
 */
function serviceClass(ContainerBuilder $container, string $id): ?string
{
    if (!$container->has($id)) {
        return class_exists($id) ? $id : null;
    }

    $definition = $container->findDefinition($id);
    $class      = $definition->getClass();

    while ($class === null && $definition instanceof ChildDefinition) {
        try {
            $definition = $container->findDefinition($definition->getParent());
        } catch (ServiceNotFoundException) {
            return null;
        }

        $class = $definition->getClass();
    }

    $class = $container->getParameterBag()->resolveValue($class ?? $id);

    return is_string($class) && class_exists($class) ? $class : null;
}


/**
 * @internal
 */
function isGrpcOnlyInterceptor(ContainerBuilder $container, string $id): bool
{
    $class = serviceClass($container, $id);

    if ($class === null) {
        return false;
    }

    $interfaces = class_implements($class);

    if (!isset($interfaces[GrpcClientInterceptor::class])) {
        return false;
    }

    foreach ($interfaces as $interface) {
        if ($interface != GrpcClientInterceptor::class && $interface != INTERCEPTOR_MARKER && is_subclass_of($interface, INTERCEPTOR_MARKER)) {
            return false;
        }
    }

    return true;
}


/**
 * @internal
 */
function isGrpcInterceptor(ContainerBuilder $container, string $id): bool
{
    $class = serviceClass($container, $id);

    return $class !== null && is_subclass_of($class, GrpcClientInterceptor::class);
}


/**
 * @internal
 */
const INTERCEPTOR_MARKER = 'Temporal\\Internal\\Interceptor\\Interceptor';


/**
 * @internal
 *
 * @param ReflectionClass<object> $reflectionClass
 *
 * @return array<int, non-empty-string>
 */
function getWorkers(ReflectionClass $reflectionClass): array
{
    $workers = array_map(static function (ReflectionAttribute $reflectionAttribute): string {
        return $reflectionAttribute->newInstance()->name;
    }, $reflectionClass->getAttributes(AssignWorker::class));

    return array_unique($workers);
}


/**
 * @internal
 *
 * @param non-empty-string $entityManager
 *
 * @return non-empty-string
 */
function doctrineInterceptorId(string $entityManager): string
{
    return sprintf('temporal.doctrine_ping_connection_%s_activity_inbound.interceptor', $entityManager);
}


/**
 * @internal
 *
 * @param non-empty-string $entityManager
 *
 * @return non-empty-string
 */
function doctrinePingFinalizerId(string $entityManager): string
{
    return sprintf('temporal.doctrine_ping_connection_%s.finalizer', $entityManager);
}


/**
 * @internal
 */
const WORKER_FACTORY_ID = 'temporal.worker_factory';


/**
 * @internal
 *
 * @param non-empty-string $workerName
 *
 * @return non-empty-string
 */
function workerId(string $workerName): string
{
    return sprintf('temporal.%s.worker', $workerName);
}


/**
 * @internal
 *
 * @return non-empty-string
 */
function doctrineClearEntityManagerFinalizerId(): string
{
    return 'temporal.doctrine_clear_entity_manager.finalizer';
}


/**
 * @internal
 *
 * @param non-empty-string $connection
 *
 * @return non-empty-string
 */
function trackingSentryDoctrineOpenTransactionInterceptorId(string $connection): string
{
    return sprintf('temporal.tracking_sentry_open_transaction_%s_activity_inbound.interceptor', $connection);
}


/**
 * @internal
 *
 * @param non-empty-string $connection
 *
 * @return non-empty-string
 */
function loggingDoctrineOpenTransactionInterceptorId(string $connection): string
{
    return sprintf('temporal.logging_open_transaction_%s_activity_inbound.interceptor', $connection);
}


/**
 * @param array<non-empty-string> $useDoctrineIntegration
 * @param array<non-empty-string> $useTrackingSentryDoctrineOpenTransaction
 * @param array<non-empty-string> $useLoggingDoctrineOpenTransaction
 *
 * @return array<non-empty-string>
 */
function getInterceptorsForIntegration(bool $useSentryIntegration, array $useDoctrineIntegration, array $useTrackingSentryDoctrineOpenTransaction, array $useLoggingDoctrineOpenTransaction): array
{
    $interceptors = [];

    $subscribers = [
        [$useDoctrineIntegration, doctrineInterceptorId(...)],
        [$useLoggingDoctrineOpenTransaction, loggingDoctrineOpenTransactionInterceptorId(...)],
        [$useTrackingSentryDoctrineOpenTransaction, trackingSentryDoctrineOpenTransactionInterceptorId(...)],
    ];

    foreach ($subscribers as [$list, $getServiceId]) {
        if ($list != []) {
            $interceptors = [
                ...$interceptors,
                ...array_map($getServiceId(...), $list),
            ];
        }
    }


    if ($useSentryIntegration) {
        $interceptors = [
            ...$interceptors,
            'temporal.sentry_workflow_outbound_calls.interceptor',
            'temporal.sentry_activity_inbound.interceptor',
        ];
    }

    return $interceptors;
}
