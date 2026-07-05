# Temporal Bundle

[Temporal](https://temporal.io/) is the simple, scalable open source way to write and run reliable cloud applications.

`vanta/temporal-bundle` integrates Temporal — the durable-execution platform for reliable, scalable
workflows — into Symfony applications. It wires the official [Temporal PHP SDK](https://github.com/temporalio/sdk-php)
and [RoadRunner](https://roadrunner.dev/) into the Symfony container, configuration, console, and web
profiler, so workflows and activities can be declared and run with idiomatic Symfony tooling.

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Assign worker](#assign-worker)
- [Doctrine integrations](#doctrine-integrations)
- [Sentry integrations](#sentry-integrations)
- [Debug commands](#debug-commands)
- [Testing](#testing)

## Features

- **Doctrine integration** — clears opened entity managers and checks the connection is still usable
  after each request; optional interceptors report unclosed transactions to Monolog/Sentry
  (if [`DoctrineBundle`](https://github.com/doctrine/DoctrineBundle) is used).
- **Sentry integration** — forwards workflow/activity throwables to Sentry
  (if [`SentryBundle`](https://github.com/getsentry/sentry-symfony) is used).
- **RoadRunner runtime** — runs workers under the bundled RoadRunner server.
- **Debug console commands** — introspect workers, clients, workflows, activities, and schedule
  clients (`debug:temporal:*`). See [Debug commands](#debug-commands).
- **Web profiler** — a data collector surfaces Temporal activity in the Symfony profiler.
- **Test helpers** — first-class support for PHPUnit and Codeception against a local Temporal test server.

## Requirements

- PHP >= 8.2
- Symfony >= 6.4 (`^6.4 || ^7 || ^8`)

## Installation

1. Connect recipes

```bash
composer config --json extra.symfony.endpoint '["https://raw.githubusercontent.com/VantaFinance/temporal-bundle/main/.recipie/index.json", "flex://defaults"]'
```

2. Install the package

```bash
composer req temporal serializer
```

3. Configure `docker-compose-temporal.yml` / `Dockerfile`.

4. Add a Workflow / Activity. See the [official examples](https://github.com/temporalio/samples-php) to get started.

## Assign worker

Run workflows and activities on a specific task queue by adding the
[`#[AssignWorker]`](src/Attribute/AssignWorker.php) attribute to your Workflow or Activity with the
name of the worker. This Workflow or Activity will then be processed by the specified worker.

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
    public function withdraw(): void;

    #[SignalMethod]
    public function deposit(): void;
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

## Doctrine integrations

Install the packages:

```bash
composer require orm temporal-doctrine
```

If [`DoctrineBundle`](https://github.com/doctrine/DoctrineBundle) is used, the following parameters
are available to you:

- `pool.useGlobalDoctrineIntegration` — connect the integration to all workers
- `pool.useGlobalLoggingDoctrineOpenTransaction` — connect an interceptor to all workers that reports unclosed transactions to Monolog
- `pool.useGlobalTrackingSentryDoctrineOpenTransaction` — connect an interceptor to all workers that reports unclosed transactions to Sentry
- `workers.useDoctrineIntegration` — connect the integration to a specific worker
- `workers.useLoggingDoctrineOpenTransaction` — connect an interceptor to a specific worker that reports unclosed transactions to Monolog
- `workers.useTrackingSentryDoctrineOpenTransaction` — connect the integration to a specific worker that reports unclosed transactions to Sentry

These parameters accept a list of entity managers.

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

**Connect the integration to all workers**

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

Install the packages:

```bash
composer require sentry temporal-sentry
```

If [`SentryBundle`](https://github.com/getsentry/sentry-symfony) is used, the following parameters
are available to you:

- `pool.useGlobalSentryIntegration` — connect the integration to all workers
- `workers.useSentryIntegration` — connect the integration to a specific worker

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

**Connect the integration to all workers**

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

## Debug commands

The bundle registers a set of `debug:temporal:*` console commands that introspect the Temporal
components registered in your application — workers, workflows, activities, clients, and schedule
clients. They are useful for verifying that your configuration and `#[AssignWorker]` attributes wire
everything up as expected.

| Command | Description | Filter argument | Printed columns |
| --- | --- | --- | --- |
| `debug:temporal:workers` | List registered workers | `workers` (worker names) | Name, Options |
| `debug:temporal:workflows` | List registered workflows | `workers` (worker names) | Id, Class, Schedule Plan, Retry Policy |
| `debug:temporal:activities` | List registered activities | `workers` (worker names) | Id, Class, IsLocalActivity, Retry Policy |
| `debug:temporal:clients` | List registered clients | `clients` (client names) | Id, Address, DataConverterId, Options |
| `debug:temporal:schedule-clients` | List registered schedule clients | `clients` (client names) | Id, Address, DataConverterId, Options |

Each command takes an optional variadic filter argument. Pass one or more names (space-separated) to
limit the output; with no argument all registered components are shown:

```bash
# All workers
bin/console debug:temporal:workers

# Only the "default" and "test" workers
bin/console debug:temporal:workers default test
```

> The `debug:temporal:workflows` and `debug:temporal:activities` commands additionally print a
> "Registered … at all workers" table for components that are registered without a specific worker,
> but only when no worker filter is passed.

### `debug:temporal:workers`

Lists every registered worker and its Temporal `WorkerOptions`.

```bash
bin/console debug:temporal:workers
```

```
Temporal Workers
================

 --------- --------------------------------------------------
  Name      Options
 --------- --------------------------------------------------
  default   {
                "maxConcurrentActivityExecutionSize": 0,
                "workerActivitiesPerSecond": 0,
                "identity": "",
                // ... full Temporal WorkerOptions payload ...
                "buildID": "",
                "useBuildIDForVersioning": false
            }
 --------- --------------------------------------------------
```

### `debug:temporal:workflows`

Lists workflows grouped by worker. Columns: `Id`, `Class`, `Schedule Plan` (cron interval or `None`),
`Retry Policy` (JSON or `None`).

```bash
bin/console debug:temporal:workflows

# Filter by worker
bin/console debug:temporal:workflows default
```

```
Temporal Workflows
==================

Worker: default
===============

 ! [NOTE] Not found workflows

Registered workflow at all workers
==================================

 ------------------- --------------------------------------------------------- --------------- --------------
  Id                  Class                                                     Schedule Plan   Retry Policy
 ------------------- --------------------------------------------------------- --------------- --------------
  TestQueryWorkflow   Vanta\...\Test\App\Workflow\TestQueryWorkflow             None            None
 ------------------- --------------------------------------------------------- --------------- --------------
```

### `debug:temporal:activities`

Lists activities grouped by worker. Columns: `Id`, `Class`, `IsLocalActivity` (`Yes`/`No`),
`Retry Policy` (JSON or `None`).

```bash
bin/console debug:temporal:activities

# Filter by worker
bin/console debug:temporal:activities default
```

```
Temporal Activities
===================

Worker: default
===============

 ! [NOTE] Not found activities
```

### `debug:temporal:clients`

Lists registered Temporal clients. Columns: `Id`, `Address`, `DataConverterId`, `Options`.

```bash
bin/console debug:temporal:clients

# Filter by client name
bin/console debug:temporal:clients default
```

```
Temporal Clients
================

Client: default
===============

 ------------------------- -------------- ------------------------- ----------------------------------
  Id                        Address        DataConverterId           Options
 ------------------------- -------------- ------------------------- ----------------------------------
  temporal.default.client   0.0.0.0:7233   temporal.data_converter   {
                                                                         "namespace": "default",
                                                                         "identity": "default_x",
                                                                         "queryRejectionCondition": 0
                                                                     }
 ------------------------- -------------- ------------------------- ----------------------------------
```

### `debug:temporal:schedule-clients`

Lists registered schedule clients. Columns: `Id`, `Address`, `DataConverterId`, `Options`.

```bash
bin/console debug:temporal:schedule-clients

# Filter by client name
bin/console debug:temporal:schedule-clients default
```

```
Temporal Schedule Clients
=========================

Client: default
===============

 ---------------------------------- -------------- ------------------------- ----------------------------------
  Id                                 Address        DataConverterId           Options
 ---------------------------------- -------------- ------------------------- ----------------------------------
  temporal.default.schedule_client   0.0.0.0:7233   temporal.data_converter   {
                                                                                  "namespace": "default",
                                                                                  "identity": "default_x",
                                                                                  "queryRejectionCondition": 0
                                                                              }
 ---------------------------------- -------------- ------------------------- ----------------------------------
```

## Testing

The following testing frameworks are supported:

- [`PHPUnit`](https://github.com/sebastianbergmann/phpunit)
- [`Codeception`](https://github.com/Codeception/Codeception)

The following parameters are available to you:

- `pool.testing.enabled` — activate test mode
- `pool.testing.activityMocker` — which ActivityMocker to use. Default value: `rr_kv`. Allowed values:
  `rr_kv`, `in_memory`, or a service id if you want to use your own implementation of
  `Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface`
- `pool.testServices.<name>` — list of configured `Temporal\Testing\TestService`

Available environment variables:

- `TEMPORAL_TESTING_RR_COMMAND` — full command to run RoadRunner
- `TEMPORAL_TESTING_NEED_DOWNLOAD_RR` — download the RoadRunner executable every time you run tests (default: `true`)
- `TEMPORAL_TESTING_SKIP_START_TEMPORAL_SERVER` — do not start the Temporal Test Server every time you run a test (default: `false`)

### Test environment setup

Both frameworks share the same setup. The bundle's testing extension boots a full
Temporal environment for you — it downloads the RoadRunner binary if it is missing,
starts the [Temporal test server](https://docs.temporal.io/dev-guide/php/testing) and
a RoadRunner worker before the run, and stops them afterwards. You only need to
provide three things.

**1. Enable test mode** in your Temporal config (`config/packages/temporal.yaml`):

```yaml
temporal:
  defaultClient: default
  pool:
    dataConverter: temporal.data_converter
    roadrunnerRPC: '%env(RR_RPC)%'
    testing:
      enabled: true

  workers:
    default:
      taskQueue: default
      exceptionInterceptor: temporal.exception_interceptor

  clients:
    default:
      namespace: default
      address: '%env(TEMPORAL_ADDRESS)%'
      dataConverter: temporal.data_converter
```

**2. Provide a RoadRunner worker entry point** (referenced by the RoadRunner config
below). It boots the Symfony kernel through the bundled `TemporalRuntime`:

```php
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputInterface as Input;

require __DIR__ . '/../vendor/autoload_runtime.php';

return static function (Input $input, array $context): object {
    $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

    if (array_key_exists('RR_MODE', $context) && $context['RR_MODE'] == 'temporal') {
        return $kernel;
    }

    return new Application($kernel);
};
```

**3. Add a RoadRunner test config** named `.rr.temporal.testing.yaml` (the extension
runs `rr serve -c .rr.temporal.testing.yaml`):

```yaml
version: '3'

server:
  command: 'php public/index.php'
  env:
    - APP_RUNTIME: Vanta\Integration\Symfony\Temporal\Runtime\TemporalRuntime

rpc:
  listen: tcp://0.0.0.0:6001

temporal:
  address: '0.0.0.0:7233'
  activities:
    num_workers: 1

logs:
  level: error
```

The environment behavior is controlled by the variables already listed above —
`TEMPORAL_TESTING_RR_COMMAND`, `TEMPORAL_TESTING_NEED_DOWNLOAD_RR`, and
`TEMPORAL_TESTING_SKIP_START_TEMPORAL_SERVER`.

### Using with PHPUnit

Add the extension to your PHPUnit XML config:

```xml
<phpunit>
    ...
    <extensions>
        <bootstrap class="Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\IntegrationTestingExtension" />
    </extensions>
</phpunit>
```

### Testing workflows with PHPUnit

Extend the base test case
`Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase` (it extends
Symfony's `KernelTestCase`). Its `setUp()` pulls three services from the container:

- `$this->workflowClient` — `Temporal\Client\WorkflowClientInterface`, used to start and query workflows
- `$this->activityMocker` — `Temporal\Testing\ActivityMocker`, used to stub activity results
- `$this->testService` — `Temporal\Testing\TestService`, used to control the test server clock

Given a workflow with a `#[WorkflowMethod]` and a `#[QueryMethod]`:

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use Generator;
use Temporal\Workflow\QueryMethod;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class TestQueryWorkflow
{
    public function __construct(
        private ?TestQuery $args = null,
    ) {
    }

    #[WorkflowMethod]
    public function start(TestQuery $args): Generator
    {
        $this->args = $args;

        yield null;
    }

    #[QueryMethod]
    public function getArgs(): ?TestQuery
    {
        return $this->args;
    }
}
```

start it through the workflow client and assert on the query result:

```php
<?php

declare(strict_types=1);

namespace App\Tests\E2e;

use App\Workflow\TestQuery;
use App\Workflow\TestQueryWorkflow;
use DateTimeImmutable;
use DateTimeZone;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

use function PHPUnit\Framework\assertEquals;

final class WorkflowTest extends TemporalTestCase
{
    public function testStartWorkflow(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(TestQueryWorkflow::class);

        $args = new TestQuery(
            'Vlad Shashkov',
            new DateTimeImmutable('2026-03-08 12:30:45', new DateTimeZone('UTC')),
        );

        $this->workflowClient->start($workflow, $args);

        assertEquals($args, $workflow->getArgs());
    }
}
```

For workflows that depend on activities, stub them via `$this->activityMocker`
before starting the workflow. To assert on asynchronous state, poll with the
provided helpers `Vanta\Integration\Symfony\Temporal\Testing\Tools\awaitWithTimeout()`
or `waitWithTime()` (both accept a `callable(): bool` condition and a `CarbonInterval`
timeout, default 5 seconds).

### Using with Codeception

Add the extension to your Codeception config (`codeception.yml`):

```yaml
extensions:
    enabled:
        - Vanta\Integration\Symfony\Temporal\Testing\Codeception\IntegrationTestingExtension:
```

### Testing workflows with Codeception

The Codeception path exposes the same three services through the
`Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalTestingTools` helper,
which is built from the Codeception `Symfony` module. Enable that module in your
suite config (`tests/Functional.suite.yml`) and add a small helper that hands the
tools to your tests:

```yaml
actor: FunctionalTester
modules:
    enabled:
        - Symfony:
            app_path: 'src'
            environment: 'test'
        - \App\Tests\Support\Helper\Temporal
```
