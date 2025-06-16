# Temporal Bundle

[Temporal](https://temporal.io/) is the simple, scalable open source way to write and run reliable cloud applications.

## Features

- **Sentry**: Send throwable events (if the [`SentryBundle`](https://github.com/getsentry/sentry-symfony) use)
- **Doctrine**: clear opened managers and check connection is still usable after each request (
  if [`DoctrineBundle`](https://github.com/doctrine/DoctrineBundle) is use)
- **Serializer**: Deserialize and serialize messages (if [`Symfony/Serializer`](https://github.com/symfony/serializer)
  is use, **Recommend use**)

## Requirements:

- php >= 8.2
- symfony >= 6.4

## Installation:

1. Connect recipes

```bash
composer config --json extra.symfony.endpoint '["https://raw.githubusercontent.com/VantaFinance/temporal-bundle/main/.recipie/index.json", "flex://defaults"]' 
```

2. Install package

```bash
composer req temporal serializer
```

3. Configure docker-compose-temporal.yml/Dockerfile

4. Added Workflow/Activity. See [examples](https://github.com/temporalio/samples-php) to get started.

## Doctrine integrations

Install packages:

```bash
composer require orm temporal-doctrine
```


If [`DoctrineBundle`](https://github.com/doctrine/DoctrineBundle) is use, the following parameters is available to you:

- `pool.useGlobalDoctrineIntegration` - Connect integration to all workers
- `pool.useGlobalLoggingDoctrineOpenTransaction`        - Connect interceptor to all workers that report unclosed transaction to monolog
- `pool.useGlobalTrackingSentryDoctrineOpenTransaction` - Connect interceptor to all workers that report unclosed transaction to sentry
- `workers.useDoctrineIntegration`                      - Connect the integration to a specific worker
- `workers.useLoggingDoctrineOpenTransaction`           - Connect interceptor to a specific worker that report unclosed transaction to monolog
- `workers.useTrackingSentryDoctrineOpenTransaction`    - Connect the integration to a specific worker that report unclosed transaction to sentry

These parameters accept a list of entity-managers

Example config:


**Specific worker**

```yaml
temporal:
  defaultClient: default
  pool:
    dataConverter: temporal.data_converter
    roadrunnerRPC: '%env(RR_RPC)%'

  workers:
    default:
      taskQueue: default
      exceptionInterceptor: temporal.exception_interceptor
      useDoctrineIntegration: 
        - default

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
    cloud:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
      clientKey: '%env(TEMPORAL_CLIENT_KEY_PATH)%'
      clientPem: '%env(TEMPORAL_CLIENT_CERT_PATH)%'
```

**Connect integration to all workers**

```yaml
temporal:
  defaultClient: default
  pool:
    dataConverter: temporal.data_converter
    roadrunnerRPC: '%env(RR_RPC)%'
    useGlobalDoctrineIntegration:
      - default

  workers:
    default:
      taskQueue: default
      exceptionInterceptor: temporal.exception_interceptor

    test:
      taskQueue: test
      exceptionInterceptor: temporal.exception_interceptor

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
    cloud:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
      clientKey: '%env(TEMPORAL_CLIENT_KEY_PATH)%'
      clientPem: '%env(TEMPORAL_CLIENT_CERT_PATH)%'
```




## Sentry integrations

Install packages:

```bash
composer require sentry temporal-sentry
```

If [`SentryBundle`](https://github.com/getsentry/sentry-symfony) is use, the following parameters is available to you:

- `pool.useGlobalSentryIntegration` - Connect integration to all workers
- `workers.useSentryIntegration` -    Connect the integration to a specific worker

Example config:

**Specific worker**

```yaml
temporal:
  defaultClient: default
  pool:
    dataConverter: temporal.data_converter
    roadrunnerRPC: '%env(RR_RPC)%'

  workers:
    default:
      taskQueue: default
      exceptionInterceptor: temporal.exception_interceptor
      useSentryIntegration: true

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
```

**Connect integration to all workers**

```yaml
temporal:
  defaultClient: default
  pool:
    dataConverter: temporal.data_converter
    roadrunnerRPC: '%env(RR_RPC)%'
    useGlobalSentryIntegration: true

  workers:
    default:
      taskQueue: default
      exceptionInterceptor: temporal.exception_interceptor

    test:
      taskQueue: test
      exceptionInterceptor: temporal.exception_interceptor  

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
```




## Testing

The following testing frameworks are supported:
- [`PHPUnit`](https://github.com/sebastianbergmann/phpunit)
- [`Codeception`](https://github.com/Codeception/Codeception)



The following parameters is available to you:

- `pool.testing.enabled` - Activate test mode
- `pool.testing.activityMocker` - Which ActivityMocker to use. default value: rr_kv, Allowed values: rr_kv, in_memory or service id
  if you want to use your own implementation ``Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface``
- `pool.testServices.<name>` - List configured  ```Temporal\Testing\TestService```



### Using with PHPUnit

Add the Extension to your PHPUnit XML config

```xml
<phpunit>
    ...
    <extensions>
        <bootstrap class="Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\IntegrationTestingExtension" />
    </extensions>
</phpunit>
```


Added new env to .env.test

```env
TEMPORAL_ADDRESS=0.0.0.0:7233
RR_RPC=tcp://0.0.0.0:6001
```


### Using with Codeception

TODO...





## Assign worker

Running workflows and activities with different task queue
Add a [`AssignWorker`](src/Attribute/AssignWorker.php) attribute to your Workflow or Activity with the name of the
worker. This Workflow or Activity will be processed by the specified worker.

**Workflow example:**

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;
use Temporal\Workflow\WorkflowInterface;

#[AssignWorker(name: 'worker1')]
#[WorkflowInterface]
final class MoneyTransferWorkflow
{
    #[WorkflowMethod]
    public function transfer(...): \Generator;

    #[SignalMethod]
    function withdraw(): void;

    #[SignalMethod]
    function deposit(): void;
}
```

**Activity example:**

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[AssignWorker(name: 'worker1')]
#[ActivityInterface(...)]
final class MoneyTransferActivity
{
    #[ActivityMethod]
    public function transfer(...): int;

    #[ActivityMethod]
    public function cancel(...): bool;
}
```

## TODO

- E2E test
- documentation
