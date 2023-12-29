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
- symfony >= 6.0

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

If [`DoctrineBundle`](https://github.com/doctrine/DoctrineBundle) is use, the following finalizer is available to you:

- `temporal.doctrine_ping_connection_<entity-mananger-name>.finalizer`
- `temporal.doctrine_clear_entity_manager.finalizer`


And interceptors: 
- `temporal.doctrine_ping_connection_<entity-mananger-name>_activity_inbound.interceptorr`


Example config:

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
      finalizers: 
        - temporal.doctrine_ping_connection_default.finalizer
        - temporal.doctrine_clear_entity_manager.finalizer
      interceptors:
        - temporal.doctrine_ping_connection_default.activity.interceptor

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
```




## Sentry integrations

If [`SentryBundle`](https://github.com/getsentry/sentry-symfony) is use, the following interceptors is available to you:

- `temporal.sentry_workflow_outbound_calls.interceptor`
- `temporal.sentry_activity_inbound.interceptor`




Example config:

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
      interceptors:
        - temporal.sentry_workflow_outbound_calls.interceptor
        - temporal.sentry_activity_inbound.interceptor

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
```







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
