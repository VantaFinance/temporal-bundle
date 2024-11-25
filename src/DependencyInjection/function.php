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
use Symfony\Component\DependencyInjection\Definition;
use Temporal\Client\Common\RpcRetryOptions;
use Temporal\Client\GRPC\Context;
use Temporal\Client\GRPC\ServiceClient as GrpcServiceClient;
use Temporal\Client\GRPC\ServiceClientInterface as ServiceClient;
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
 *
 * @param array{
 *  address: non-empty-string,
 *  clientKey: ?non-empty-string,
 *  clientPem: ?non-empty-string,
 *  grpcContext: array<string, mixed>
 * } $client
 */
function grpcClient(array $client): Definition
{
    $serviceClient = definition(ServiceClient::class, [$client['address']])
        ->setFactory([GrpcServiceClient::class, 'create'])
    ;

    if (($client['clientKey'] ?? false) && ($client['clientPem'] ?? false)) {
        $serviceClient = definition(ServiceClient::class, [
            $client['address'],
            null, // root CA - Not required for Temporal Cloud
            $client['clientKey'],
            $client['clientPem'],
            null, // Overwrite server name
        ])->setFactory([GrpcServiceClient::class, 'createSSL']);
    }

    return $serviceClient->addMethodCall('withContext', [
        grpcContext($client['grpcContext']),
    ], true);
}


/**
 * @internal
 *
 * @param array{} $rawContext
 */
function grpcContext(array $rawContext): Definition
{
    $context = definition(Context::class)
        ->setFactory([Context::class, 'default'])
    ;

    /** @phpstan-ignore-next-line **/
    foreach ($rawContext as $name => $value) {
        $method = sprintf('with%s', ucfirst($name));

        if (!method_exists(Context::class, $method)) {
            continue;
        }


        /** @phpstan-ignore-next-line **/
        if (array_key_exists('value', $value) && array_key_exists('format', $value)) {
            $context->addMethodCall($method, [$value['value'], $value['format']], true);

            continue;
        }

        /** @phpstan-ignore-next-line **/
        if ($name == 'retryOptions') {
            $rawRetryOptions = $value;
            $value           = definition(RpcRetryOptions::class)
                ->setFactory([RpcRetryOptions::class, 'new'])
            ;

            foreach ($rawRetryOptions as $retryOptionName => $retryOptionValue) {
                $retryMethod = sprintf('with%s', ucfirst($retryOptionName));

                if (!method_exists(RpcRetryOptions::class, $retryMethod)) {
                    continue;
                }

                if (str_ends_with($retryOptionName, 'Timeout') || str_ends_with($retryOptionName, 'Interval')) {
                    if ($retryOptionValue == null) {
                        continue;
                    }

                    $retryOptionValue = definition(DateInterval::class)
                        ->setFactory([DateInterval::class, 'createFromDateString'])
                        ->setArguments([
                            $retryOptionValue,
                        ])
                    ;
                }

                $value->addMethodCall($retryMethod, [$retryOptionValue], true);
            }


            $context->addMethodCall($method, [$value], true);

            continue;
        }


        $context->addMethodCall($method, [$value], true);
    }

    return $context;
}


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
