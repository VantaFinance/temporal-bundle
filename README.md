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
- [Starting a workflow](#starting-a-workflow)
- [Assign worker](#assign-worker)
- [Doctrine integrations](#doctrine-integrations)
- [Sentry integrations](#sentry-integrations)
- [HTTP with roadrunner-symfony/http-bundle](#http-with-roadrunner-symfonyhttp-bundle)
- [RoadRunner 3](#roadrunner-3)
- [Connections: TLS, API key, Temporal Cloud](#connections-tls-api-key-temporal-cloud)
- [Environment configuration profiles](#environment-configuration-profiles)
- [Worker options](#worker-options)
- [Feature flags](#feature-flags)
- [Plugins](#plugins)
- [Data conversion and payload limits](#data-conversion-and-payload-limits)
- [Interceptors](#interceptors)
- [Additional clients](#additional-clients)
- [Replaying workflows](#replaying-workflows)
- [Debug commands](#debug-commands)
- [Troubleshooting](#troubleshooting)
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
- **temporal/sdk 2.16 – 2.20** — TLS and API keys, Temporal Cloud, environment configuration profiles, Worker
  Deployment Versioning, feature flags, plugins, gRPC interceptors, payload limits, operator and activity completion
  clients, workflow replay.
- **RoadRunner 2025 and RoadRunner 3** — see [RoadRunner 3](#roadrunner-3).
- **Test helpers** — PHPUnit base test case, Codeception helper, and explicit, code-driven control of Temporal and
  RoadRunner test servers.

## Requirements

- PHP >= 8.2
- Symfony >= 6.4 (`^6.4 || ^7 || ^8`)
- temporal/sdk >= 2.16 and RoadRunner, in one of the lines:

| temporal/sdk  | RoadRunner server | RoadRunner PHP packages              | Notes                                               |
|---------------|-------------------|--------------------------------------|-----------------------------------------------------|
| 2.16 – 2.19   | 2025.x            | `spiral/roadrunner*`                 |                                                     |
| 2.16 – 2.19   | 3.x               | `spiral/roadrunner*`                 | works; every worker logs a version-checker warning  |
| >= 2.20       | 3.x               | `roadrunner/*` (replace `spiral/*`)  | needs Carbon 3                                      |

Options below marked `SDK >= x` need that temporal/sdk version. If you set one on an older SDK, the container build fails
and names the version to install. Options you leave unset are not passed to the SDK.

## Installation

1. Connect recipes

```bash
composer config --json extra.symfony.endpoint '["https://raw.githubusercontent.com/VantaFinance/temporal-bundle/main/.recipie/index.json", "flex://defaults"]'
```

2. Install the package

```bash
composer req temporal serializer
```

3. Check that the bundle is enabled. `config/bundles.php` must contain

```php
Vanta\Integration\Symfony\Temporal\TemporalBundle::class => ['all' => true],
```

and `config/packages/temporal.yaml` must exist. Without the recipe endpoint from step 1, Flex does not create
`temporal.yaml`: copy [`temporal.yaml`](temporal.yaml) from this repository.

4. Configure `.docker/docker-compose-temporal.yml` and `.docker/Dockerfile` (the recipe copies both).

5. Add a workflow and an activity. Step 3 of the [Testing quick start](#quick-start) has a minimal
`GreetingWorkflow` with a `GreetingActivity`; the [official samples](https://github.com/temporalio/samples-php) show
more.

## Starting a workflow

The default client (`defaultClient`) is autowired as `Temporal\Client\WorkflowClientInterface`. Another client is
injected by argument name, `$<name>WorkflowClient` (for example `$barWorkflowClient` for the client `bar`).

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Workflow\GreetingWorkflow;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;

final class Greeter
{
    public function __construct(
        private readonly WorkflowClientInterface $workflowClient,
    ) {
    }

    public function greet(string $name): string
    {
        $workflow = $this->workflowClient->newWorkflowStub(
            GreetingWorkflow::class,
            WorkflowOptions::new()->withTaskQueue('default'),
        );

        $run = $this->workflowClient->start($workflow, $name);

        return $run->getResult('string', 10);
    }
}
```

The task queue must match the `taskQueue` of the worker that runs the workflow. `start()` returns once Temporal
accepts the workflow; `getResult()` waits for it to finish, so skip it when the caller does not need the result.

## Assign worker

Every workflow and activity runs on one or more workers:

- With [`#[AssignWorker('name')]`](src/Attribute/AssignWorker.php) it runs on the named worker only. The attribute is
  repeatable: add it once per worker.
- Without the attribute it runs on the default worker: `default`, or the worker set in `defaultWorker`.
- A name that is not configured under `temporal.workers` stops the container build with an error that names the class
  and the configured workers.

```yaml
temporal:
  defaultWorker: default
  workers:
    default:
      taskQueue: default
    worker1:
      taskQueue: worker1
```

> **Breaking change in 0.5.** Before 0.5 a workflow or activity without `#[AssignWorker]` ran on every worker; now it
> runs on the default worker only. To keep a class on several workers, repeat `#[AssignWorker]` for each of them. If
> some classes have no attribute and no worker matches `defaultWorker`, the container build fails.

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
      tls:
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
      tls:
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

## HTTP with roadrunner-symfony/http-bundle

[roadrunner-symfony/http-bundle](https://github.com/roadrunner-symfony/http-bundle) serves HTTP requests from the same
application. HTTP and Temporal run in two RoadRunner processes, each with its own config and runtime:

| Config              | `server.env.APP_RUNTIME`                                     |
|---------------------|--------------------------------------------------------------|
| `.rr.http.yaml`     | `Roadrunner\Integration\Symfony\Http\Runtime\HttpRuntime`        |
| `.rr.temporal.yaml` | `Vanta\Integration\Symfony\Temporal\Runtime\TemporalRuntime`     |

Both processes start `php public/index.php`. With `HttpRuntime` the kernel serves HTTP requests, with
`TemporalRuntime` it polls Temporal. Install both bundles through Flex with both recipe endpoints:

```bash
composer config --json extra.symfony.endpoint '["https://raw.githubusercontent.com/roadrunner-symfony/recipes/main/index.json", "https://raw.githubusercontent.com/VantaFinance/temporal-bundle/main/.recipie/index.json", "flex://defaults"]'
composer req http temporal serializer
```

and run each process with its config:

```bash
rr serve -c .rr.http.yaml
rr serve -c .rr.temporal.yaml
```

Both configs listen for RPC on port 6001 (`rpc.listen`). That works when the processes run in separate containers; on
one host, move one of them to another port. `RR_RPC` must point to the Temporal process, because the worker factory
and the `rr_kv` activity mocker connect to it.

Sentry and Doctrine integrations are configured in each bundle: `road_runner_http` covers HTTP requests (see the
[http-bundle README](https://github.com/roadrunner-symfony/http-bundle#sentry-integrations)), `temporal` covers
workflows and activities ([Doctrine](#doctrine-integrations), [Sentry](#sentry-integrations)).

Another HTTP runtime, for example Baldinof RoadRunnerBundle, works the same way: it gets its own RoadRunner config and
`APP_RUNTIME`, and Temporal stays in `.rr.temporal.yaml`.

## RoadRunner 3

RoadRunner moved from calendar versions (the last one is `2025.1.x`) to semantic versions (`3.x`). Its PHP packages
moved from `spiral/*` to `roadrunner/*`; the PHP namespaces stayed the same. temporal/sdk 2.20 requires RoadRunner 3.
temporal/sdk 2.16 – 2.19 run on RoadRunner 2025, and on RoadRunner 3 with a version-checker warning from every worker.

Download the binary that matches your temporal/sdk:

```bash
vendor/bin/dload get rr:^3 temporal      # temporal/sdk >= 2.20
vendor/bin/dload get rr:^2025 temporal   # temporal/sdk 2.16 - 2.19
```

The recipe files `.rr.temporal.yaml` and `.rr.temporal.testing.yaml` work on both versions. If you move your own
RoadRunner config to RoadRunner 3, change these parts:

- **Logs.** RoadRunner 3 ignores `logs.file_logger_options` and `logs.encoding`. Write the log file with `logs.output`,
  relative to the working directory (`-w`). RoadRunner 2025 reads `file_logger_options` before `output`, so keep only
  `output` while you run both versions:

  ```yaml
  logs:
    mode: production
    level: info
    output: var/log/rr-worker.log
  ```

- **Memory KV storage.** RoadRunner 3 ignores `config.interval`. Use `config: {}`, which works on both versions.
- **Temporal plugin.** RoadRunner 3 adds `temporal.worker_heartbeat_interval`, `temporal.disable_activity_workers` and
  `temporal.data_converters`.
- **RPC.** Keep `rpc.listen`. Neither version opens an RPC listener by default, and the worker factory, the `rr_kv`
  mocks and `temporal:workflow:replay` connect to it.
- **HTTP.** RoadRunner 3 runs the HTTP middleware in reverse order. If you use
  [roadrunner-symfony/http-bundle](#http-with-roadrunner-symfonyhttp-bundle), check the order of `http.middleware`.

`bin/console debug:temporal:workers` prints the installed temporal/sdk version and which RoadRunner packages are
installed.

## Connections: TLS, API key, Temporal Cloud

The bundle decides how a client connects when the application starts, after env variables are resolved. Each client
and schedule client has its own connection:

```yaml
temporal:
  clients:
    cloud:
      namespace: my-namespace.a2dd6
      address: '%env(TEMPORAL_ADDRESS)%'              # my-namespace.a2dd6.tmprl.cloud:7233
      apiKey: '%env(TEMPORAL_API_KEY)%'               # or "@service_id" of a \Stringable service, to rotate the key
      tls:
        enabled: ~                                    # ~: auto, true: always TLS, false: no TLS
        rootCa: ~                                     # file path or PEM content; system roots by default
        clientKey: '%env(TEMPORAL_CLIENT_KEY_PATH)%'  # mTLS, set together with clientPem
        clientPem: '%env(TEMPORAL_CLIENT_CERT_PATH)%'
        serverName: ~                                 # overrides the TLS server name
```

With `tls.enabled: ~` (the default) the client uses TLS when a certificate, `serverName` or a non-empty `apiKey` is set,
so an API key is never sent without TLS. If the `apiKey` env variable is empty, a local server keeps working without
TLS. To send a key to a server without TLS, set `tls.enabled: false`.

`clientKey` and `clientPem` at the client level still work but are deprecated. Move them to `tls.clientKey` and
`tls.clientPem`.

`grpcContext` applies to every call of the client:

```yaml
temporal:
  clients:
    default:
      namespace: default
      grpcContext:
        timeout: { value: 5, format: seconds }
        metadata:                                  # gRPC headers, added to the SDK ones (client-name, client-version)
          x-team: billing
        retryOptions:                              # unset values keep the SDK defaults: 500 ms, unlimited attempts
          initialInterval: '1 second'
          maximumInterval: '30 seconds'
          backoffCoefficient: 2.0                  # "backoff_coefficient" still works, deprecated
          maximumAttempts: 10
          congestionInitialInterval: '2 seconds'   # RESOURCE_EXHAUSTED errors
          maximumJitterCoefficient: 0.2            # from 0 to below 1
```

## Environment configuration profiles

SDK >= 2.17. The SDK marks this feature experimental. A client can read its connection from a
[Temporal environment configuration](https://docs.temporal.io/develop/environment-configuration) profile: a TOML file
plus the `TEMPORAL_*` env variables (`TEMPORAL_ADDRESS`, `TEMPORAL_NAMESPACE`, `TEMPORAL_API_KEY`, `TEMPORAL_TLS_*`,
`TEMPORAL_GRPC_META_*`, `TEMPORAL_PROFILE`, `TEMPORAL_CONFIG_FILE`):

```yaml
temporal:
  clients:
    default:
      envConfig:
        enabled: true
        profile: prod                                    # default: TEMPORAL_PROFILE, then "default"
        configFile: '%kernel.project_dir%/temporal.toml'  # default: TEMPORAL_CONFIG_FILE, then the platform path
      # namespace: overrides the profile namespace
```

With `envConfig.enabled` you cannot set `address`, `apiKey` or `tls`: the profile provides them. Reading a TOML file
needs `composer require internal/toml`.

## Worker options

Workers accept these options in addition to the earlier ones:

```yaml
temporal:
  workers:
    default:
      taskQueue: default
      identity: billing-worker-1
      workflowPanicPolicy: FailWorkflow          # BlockWorkflow (default) or FailWorkflow
      taskQueueActivitiesPerSecond: 0.5
      exceptionInterceptor:                      # a service id, or the workflow task errors to retry
        retryableErrors: [ App\Exception\TransientException ]
      deploymentOptions:                         # Worker Deployment Versioning, RoadRunner >= 2025.1.3
        useVersioning: true
        deploymentName: billing
        buildId: '%env(GIT_COMMIT)%'
        defaultVersioningBehavior: Pinned        # Unspecified, Pinned or AutoUpgrade
      maxConcurrentNexusTaskExecutionSize: 10
      maxConcurrentNexusTaskPollers: 2
      maxEagerActivityReservationsPerWorkflowTask: 3   # SDK >= 2.20, RoadRunner 3
      disablePayloadErrorLimit: true                   # SDK >= 2.20
```

`buildID` and `useBuildIDForVersioning` are deprecated; use `deploymentOptions`. `useBuildIDForVersioning` cannot be
combined with `enableSessionWorker` or with `deploymentOptions.useVersioning`.

Options for all workers:

- `pool.dataConverter` defaults to `temporal.data_converter`. `pool.roadrunnerRPC` defaults to `RR_RPC`, or
  `tcp://127.0.0.1:6001` when `RR_RPC` is not set. You can leave out `pool` entirely.
- `pool.rpc` is the id of your `Temporal\Worker\Transport\RPCConnectionInterface` service. The workers use it instead
  of connecting to `pool.roadrunnerRPC`.
- `pool.pipelineProvider` is the id of your `Temporal\Interceptor\PipelineProvider` service. It replaces the bundle
  interceptor pipeline of the workers, so the container build fails if bundle interceptors or integrations are also
  configured.
- `pool.workerFactory` is your `Temporal\Worker\WorkerFactoryInterface` class. On SDK >= 2.18 it must implement
  `getPluginRegistry()`. In test mode its `create()` needs an `$activityCache` parameter.

## Feature flags

`pool.featureFlags` sets `Temporal\Worker\FeatureFlags` before any worker starts. A flag you leave at `~` (the default)
keeps the SDK value:

| Flag                                      | SDK default | SDK     |
|-------------------------------------------|-------------|---------|
| `workflowDeferredHandlerStart`            | false       | >= 2.11 |
| `warnOnWorkflowUnfinishedHandlers`        | true        | >= 2.11 |
| `cancelAbandonedChildWorkflows`           | true        | >= 2.16 |
| `throwDestructMemorizedInstanceException` | true        | >= 2.16 |
| `warnOnActivityMethodWithoutAttribute`    | true        | >= 2.17 |
| `propagateCancellationToNewScopes`        | false       | >= 2.18 |
| `settleAwaitOnFirstSettledCondition`      | false       | >= 2.19 |
| `readOnlyWorkflowCallbacks`               | true        | >= 2.20 |

After an upgrade to SDK 2.20, query handlers and update validators can no longer change the workflow state
(`readOnlyWorkflowCallbacks`). Set the flag to `false` to keep the old behaviour while you fix them.
`settleAwaitOnFirstSettledCondition` changes how running workflows replay.

```yaml
temporal:
  pool:
    featureFlags:
      readOnlyWorkflowCallbacks: false
```

The flags are static, so they stay set for the rest of the PHP process, including other kernels booted in the same
PHPUnit run.

## Plugins

SDK >= 2.18. The bundle registers every service that implements `Temporal\Plugin\PluginInterface` (for example by
extending `Temporal\Plugin\AbstractPlugin`) in every workflow client, schedule client and the worker factory. Each of
them gets a separate plugin registry, so the SDK never sees the same plugin name twice.

To limit a plugin to some clients or to the workers, add `#[AsPlugin]`:

```php
use Temporal\Plugin\AbstractPlugin;
use Vanta\Integration\Symfony\Temporal\Attribute\AsPlugin;

#[AsPlugin(clients: ['billing'], workers: true)]
final class TracingPlugin extends AbstractPlugin
{
    public function __construct()
    {
        parent::__construct('acme.tracing');
    }
}
```

Plugins can also be listed in the configuration:

```yaml
temporal:
  pool:
    plugins: [ app.global_plugin ]     # every client, schedule client and the workers
    client: default                    # the worker factory gets this client and its worker plugins
  clients:
    default:
      namespace: default
      plugins: [ app.client_plugin ]   # this client only
```

## Data conversion and payload limits

By default `temporal.data_converter` serializes payloads to JSON with the Symfony Serializer. On SDK >= 2.20 your
normalizers receive the serialization context (namespace, workflow id) in the serializer context under the
`temporal_serialization_context` key.

```yaml
temporal:
  pool:
    useSdkDefaultConverters: true            # null, binary/plain (Bytes), json/protobuf, binary/protobuf, then JSON
    payloadConverters: [ app.my_converter ]  # tried first; replaces the converter with the same encoding
```

`useSdkDefaultConverters` is off by default. Workers and clients without it cannot read payloads in the new encodings,
so turn it on only after all of them run the new configuration.

SDK >= 2.20 adds payload and memo size warnings and a logger per client:

```yaml
temporal:
  clients:
    default:
      namespace: default
      logger: monolog.logger.temporal     # default: pool.globalLogger
      payloadLimits:
        payloadSizeWarning: 262144        # bytes; the SDK default is 512 KiB
        memoSizeWarning: 1024             # bytes; the SDK default is 2 KiB
        # enabled: false                  # turns the warnings off
```

## Interceptors

`interceptors` of a client or worker and `pool.globalInterceptors` take both workflow interceptors and gRPC
interceptors (`Temporal\Interceptor\GrpcClientInterceptor`). gRPC interceptors run on the connection of every client
and schedule client; the others run in the client and worker pipelines. Schedule clients take only gRPC interceptors.
If your interceptor service is built by a factory, set its `class`, so that the bundle can tell the two kinds apart.

## Additional clients

Each workflow client `<name>` also provides:

| Autowiring                                                                          | SDK      |
|-------------------------------------------------------------------------------------|----------|
| `Temporal\Client\ActivityCompletionClientInterface $<name>ActivityCompletionClient` |          |
| `Temporal\Client\GRPC\OperatorClientInterface $<name>OperatorClient`                | >= 2.18  |

The default client's ones are also autowired by the interface alone, unless your application already defines a service
with that id.

SDK >= 2.19 adds a client for the Temporal Cloud Ops API, autowired as `Temporal\Client\GRPC\CloudClientInterface`:

```yaml
temporal:
  cloudClient:
    enabled: true
    apiKey: '%env(TEMPORAL_CLOUD_API_KEY)%'   # or "@service_id"
    # address: saas-api.tmprl.cloud:443
    # apiVersion: '2024-10-01-00'
```

## Replaying workflows

`temporal:workflow:replay` replays a workflow history against your current workflow code. It exits with code 1 when the
history is not deterministic. The replay runs inside RoadRunner, so start RoadRunner with your workers first; the
command connects to `RR_RPC` or `pool.roadrunnerRPC`.

```bash
bin/console temporal:workflow:replay OrderWorkflow --workflow-id=order-42 [--run-id=...]
bin/console temporal:workflow:replay OrderWorkflow --history-file=var/history.json [--last-event-id=12]
```

## Debug commands

The bundle registers a set of `debug:temporal:*` console commands that introspect the Temporal
components registered in your application — workers, workflows, activities, clients, and schedule
clients. They are useful for verifying that your configuration and `#[AssignWorker]` attributes wire
everything up as expected.

| Command | Description | Filter argument | Printed columns |
| --- | --- | --- | --- |
| `debug:temporal:workers` | List registered workers | `workers` (worker names) | temporal/sdk and RoadRunner line, feature flags, plugins; Name, Options |
| `debug:temporal:workflows` | List registered workflows | `workers` (worker names) | Id, Class, Schedule Plan, Retry Policy |
| `debug:temporal:activities` | List registered activities | `workers` (worker names) | Id, Class, IsLocalActivity, Retry Policy |
| `debug:temporal:clients` | List registered clients | `clients` (client names) | Id, Address, Connection, Plugins, DataConverterId, Options |
| `debug:temporal:schedule-clients` | List registered schedule clients | `clients` (client names) | Id, Address, Connection, Plugins, DataConverterId, Options |

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

Prints the installed temporal/sdk version, the RoadRunner packages, the feature flags you set and the worker plugins,
then every registered worker with its Temporal `WorkerOptions`.

```bash
bin/console debug:temporal:workers
```

```
Temporal Workers
================

 --------------------- -----------------------------
  temporal/sdk          v2.20
  RoadRunner packages   roadrunner/* (RoadRunner 3)
  Feature flags         readOnlyWorkflowCallbacks: false
  Plugins               none
 --------------------- -----------------------------

 --------- --------------------------------------------------
  Name      Options
 --------- --------------------------------------------------
  default   {
                "maxConcurrentActivityExecutionSize": 0,
                "workerActivitiesPerSecond": 0,
                "identity": "",
                // ... full Temporal WorkerOptions payload ...
                "buildID": ""
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

Lists registered Temporal clients. Columns: `Id`, `Address`, `Connection`, `Plugins`, `DataConverterId`, `Options`.
`Connection` shows the TLS mode, which certificates are set, whether an API key is set, or the `envConfig` profile. It
never prints a key or certificate.

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

Lists registered schedule clients. Columns: `Id`, `Address`, `Connection`, `Plugins`, `DataConverterId`, `Options`.

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

## Troubleshooting

**A workflow or activity is not registered** (`ActivityNotRegisteredError`, unknown workflow type). Run
`bin/console debug:temporal:workflows` and `bin/console debug:temporal:activities` to see which worker has which class.
The class needs `#[WorkflowInterface]` or `#[ActivityInterface]` and must be an autoconfigured service. The worker
comes from `#[AssignWorker]` or, without it, from `defaultWorker` (see [Assign worker](#assign-worker)). Start the workflow with that worker's
`taskQueue`.

**The configuration is not what you expect.** `bin/console debug:config temporal` prints the resolved configuration;
`bin/console debug:temporal:workers` and `bin/console debug:temporal:clients` show worker options and client
addresses.

**The worker does not start.** `rr workers -c .rr.temporal.yaml` lists the PHP workers RoadRunner started. A PHP error
during kernel boot appears in the RoadRunner output. Check `RR_RPC` and `TEMPORAL_ADDRESS` in the environment of the
worker process.

**Tests cannot start Temporal or RoadRunner.** The test error (`ServerStartFailed`) contains the server command, its
log file and the tail of that log. Set the `logFile` parameter of `TemporalEnvironmentExtension` (see the
[Quick start](#quick-start)) to record every start and stop of a test run.

## Testing

Tests run against real Temporal and RoadRunner processes that are started and stopped for every test marked with
`#[WithTemporalEnvironment]`, or explicitly from your test code. The bundle provides:

- `Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment` — marks a test class or method that
  needs Temporal and RoadRunner.
- `Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension` /
  `Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalEnvironmentExtension` — start and stop the servers
  around every marked test.
- `Vanta\Integration\Symfony\Temporal\Testing\Server\*` — classes that start and stop Temporal and RoadRunner.
- `Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase` — a [`PHPUnit`](https://github.com/sebastianbergmann/phpunit)
  base test case with `$workflowClient`, `$activityMocker` and `$testService` from the container, plus
  `getWorkflowMocker()` / `getSearchAttributeMocker()` (SDK >= 2.18).
- `Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalTestingTools` — the same services for
  [`Codeception`](https://github.com/Codeception/Codeception).
- `Vanta\Integration\Symfony\Temporal\Testing\Tools\awaitWithTimeout()` / `waitWithTime()` — polling helpers.

### Quick start

The steps below take a Symfony application to a green workflow test.

**1. Install PHPUnit and download the binaries** — ideally **before** installing the bundle, so that its recipe can
wire the test setup (step 2). [`dload`](https://github.com/php-internal/dload) puts `rr` and `temporal` into the
project root:

```bash
composer require --dev symfony/test-pack internal/dload
vendor/bin/dload get rr temporal
```

Then install the bundle as described in [Installation](#installation).

**2. Check what the recipe did.** It adds the following; if PHPUnit was installed after the bundle or a file has a
different name, add the missing parts by hand:

- `config/packages/temporal.yaml` — test mode for the `test` environment only (enabling it globally would switch your
  production workers to the testing worker factory):

  ```yaml
  when@test:
    temporal:
      pool:
        testing:
          enabled: true
  ```

- `.env.test` — localhost addresses (the recipe's `.env` uses the docker host names `temporal:7233` and
  `temporal-php-worker:6001`, which do not resolve outside docker):

  ```dotenv
  ###> vanta/temporal-bundle (tests) ###
  TEMPORAL_ADDRESS=127.0.0.1:7233
  RR_RPC=tcp://127.0.0.1:6001
  ###< vanta/temporal-bundle (tests) ###
  ```

- `.rr.temporal.testing.yaml` in the project root — the RoadRunner test config. The `kv.test` storage is required by
  the default activity mocker (`pool.testing.activityMocker: rr_kv`):

  ```yaml
  version: '3'

  server:
    command: 'php public/index.php'
    env:
      - APP_RUNTIME: Vanta\Integration\Symfony\Temporal\Runtime\TemporalRuntime

  rpc:
    listen: tcp://127.0.0.1:6001

  temporal:
    address: '127.0.0.1:7233'
    activities:
      num_workers: 1

  logs:
    level: error

  kv:
    test:
      driver: memory
      config: {}
  ```

- `phpunit.dist.xml` (or `phpunit.xml.dist`) — the extension, inside the `<extensions>` block that Symfony's PHPUnit
  recipe creates. It reads `#[WithTemporalEnvironment]` and starts a fresh Temporal dev server and RoadRunner before
  every marked test (before `setUp()`) and stops them after it (after `tearDown()`). The `logFile` parameter is
  optional and records every start and stop:

  ```xml
  <extensions>
      <bootstrap class="Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension">
          <parameter name="logFile" value="var/log/test-environment.log"/>
      </bootstrap>
  </extensions>
  ```

**3. Write a workflow and a test.** `src/Workflow/GreetingActivity.php`:

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'Greeting.')]
final class GreetingActivity
{
    #[ActivityMethod]
    public function greet(string $name): string
    {
        return 'Hello, ' . $name;
    }
}
```

`src/Workflow/GreetingWorkflow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Workflow;

use Carbon\CarbonInterval;
use Generator;
use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;

#[WorkflowInterface]
final class GreetingWorkflow
{
    #[WorkflowMethod]
    public function greet(string $name): Generator
    {
        $activity = Workflow::newActivityStub(
            GreetingActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout(CarbonInterval::seconds(5)),
        );

        return yield $activity->greet($name);
    }
}
```

`tests/GreetingWorkflowTest.php` — the attribute applies to **every** test of the class:

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\GreetingWorkflow;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

#[WithTemporalEnvironment]
final class GreetingWorkflowTest extends TemporalTestCase
{
    public function testGreet(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(GreetingWorkflow::class);

        $run = $this->workflowClient->start($workflow, 'Vlad');

        self::assertSame('Hello, Vlad', $run->getResult('string', 10));
    }
}
```

The attribute finds `temporal` and `rr` in `PATH` or in the project root (where `dload` put them) and uses
`.rr.temporal.testing.yaml` from the project root — no paths in the test. The RoadRunner worker inherits
`APP_ENV=test` and the `.env.test` values from the PHPUnit process, so it boots your kernel in the `test` environment.

**4. Run it:**

```bash
vendor/bin/phpunit
```

### Mocking activities

`$this->activityMocker` replaces an activity result for the running worker. The activity name is the
`ActivityInterface` prefix plus the method name. Every test gets a new RoadRunner, so the expectations do not leak
into the next test:

```php
public function testGreetWithMockedActivity(): void
{
    $this->activityMocker->expectCompletion('Greeting.greet', 'mocked');

    $workflow = $this->workflowClient->newWorkflowStub(GreetingWorkflow::class);

    self::assertSame('mocked', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
}
```

Use `expectFailure('Greeting.greet', new \RuntimeException(...))` to simulate a failing activity. To assert on
asynchronous state, poll with `awaitWithTimeout()` or `waitWithTime()` (both take a `callable(): bool` condition and a
`CarbonInterval` timeout, default 5 seconds).

### Mocking child workflows, side effects and search attributes

SDK >= 2.18. In test mode you can also mock child workflows, side effects and search attribute updates.
`TemporalTestCase` and `TemporalTestingTools` give you `getWorkflowMocker()` and `getSearchAttributeMocker()`; both are
also autowired as `Temporal\Testing\WorkflowMocker` and `Temporal\Testing\SearchAttributeMocker`. They keep their
expectations in the same storage as the activity mocker.

Here `ParentGreetingWorkflow` starts `GreetingWorkflow` as a child workflow, and the test replaces the child result:

```php
public function testParentWithMockedChild(): void
{
    $this->getWorkflowMocker()->expectCompletion('GreetingWorkflow', 'mocked child');

    $workflow = $this->workflowClient->newWorkflowStub(ParentGreetingWorkflow::class);

    self::assertSame('parent: mocked child', $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10));
}
```

The following parameters configure test mode:

- `pool.testing.enabled` — activate test mode
- `pool.testing.activityMocker` — which ActivityMocker to use. Default value: `rr_kv`. Allowed values:
  `rr_kv`, `in_memory`, or a service id if you want to use your own implementation of
  `Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface` (SDK >= 2.18 added
  `saveConsecutiveCompletions()` and `saveCompletionWhen()` to it)
- `pool.testing.activityCacheName` — the RoadRunner KV storage of the `rr_kv` mocks. Default value: `test`; it must
  match a `kv.<name>` section of the RoadRunner config
- `pool.testing.testServices.<name>` — list of configured `Temporal\Testing\TestService`
- `pool.testing.timeLocking` — SDK >= 2.18. While a workflow client waits for a result, the time-skipping test server
  (`TemporalServerType::TestServer`) does not skip time
- `pool.testing.transcript` — SDK >= 2.18. Path of a file where the workers write every workflow and activity call

### Lifecycle per test

`#[WithTemporalEnvironment]` can be put on a test class (applies to every test, also inherited by subclasses) or on a
test method (overrides the class attribute). All arguments are optional:

| Argument              | Default                       | Meaning                                                                                       |
|-----------------------|-------------------------------|-----------------------------------------------------------------------------------------------|
| `temporal`            | `TemporalServerType::Dev`     | `Dev` — `temporal server start-dev`; `TimeSkipping` — `temporal-test-server` (needed by `TestService`); `External` — start no Temporal process, use a running one |
| `temporalAddress`     | `127.0.0.1:7233`              | Address the Temporal server listens on; also passed to RoadRunner as `-o temporal.address=…`  |
| `temporalBinary`      | `null`                        | `temporal` / `temporal-test-server` looked up in `PATH`, then in the project root             |
| `searchAttributes`    | `[]`                          | `array<name, ValueType>` registered on start (`Dev` only), e.g. `['CustomerId' => ValueType::Keyword]` |
| `temporalNamespace`   | `default`                     | Namespace created on start and used by the RoadRunner worker (`Dev` only)                     |
| `temporalDynamicConfig` | `[]`                        | `--dynamic-config-value KEY=<json>` entries (`Dev` only)                                      |
| `temporalArguments`   | `[]`                          | Extra `temporal server start-dev` flags, appended last (`Dev` only)                           |
| `roadRunnerConfig`    | `.rr.temporal.testing.yaml`   | Relative to the project root                                                                  |
| `roadRunnerBinary`    | `null`                        | `rr` looked up in `PATH`, then in the project root                                            |
| `roadRunnerOverrides` | `[]`                          | Extra `-o key=value` RoadRunner config overrides (e.g. `['rpc.listen' => 'tcp://127.0.0.1:6002']`) |
| `startTimeout`        | `30.0`                        | Seconds to wait until a server is ready                                                       |
| `stopTimeout`         | `10.0`                        | Seconds to wait for a graceful stop before killing                                            |

Relative paths are resolved against the project root (the root Composer package). Your application's Temporal client
address (`TEMPORAL_ADDRESS` in `.env.test`) must point to the same `temporalAddress`.

The extension works with any PHPUnit test — `TemporalTestCase` or a plain `TestCase`, no trait or base class needed:

```php
use PHPUnit\Framework\TestCase;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension;

#[WithTemporalEnvironment]
final class MyTest extends TestCase
{
    public function testEnvironmentIsUp(): void
    {
        self::assertTrue(TemporalEnvironmentExtension::current()?->isRunning() === true);
    }
}
```

`TemporalEnvironmentExtension::current()` returns the environment of the running test (`null` without the attribute).
For Codeception see [Testing workflows with Codeception](#testing-workflows-with-codeception).

To manage the servers yourself, leave the attribute out and start an environment in your own hooks:

```php
use Vanta\Integration\Symfony\Temporal\Testing\Server\RoadRunnerServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalDevServer;
use Vanta\Integration\Symfony\Temporal\Testing\Server\TemporalTestEnvironment;

private TemporalTestEnvironment $environment;

protected function setUp(): void
{
    $this->environment = new TemporalTestEnvironment(
        new TemporalDevServer(address: '127.0.0.1:7233'),
        new RoadRunnerServer(configFile: 'tests/rr.yaml'),
    );
    $this->environment->start();
}

protected function tearDown(): void
{
    $this->environment->stop();
}
```

Things to know:

- Starting takes about 0.5 s and stopping about 2 s per test (RoadRunner shuts its worker pools down one by one); the
  servers are stopped in parallel.
- The servers are up during `setUp()` and `tearDown()` too.
- The ports must be free: if something already answers on `temporalAddress` (for example a Temporal container),
  `start()` fails with `ServerStartFailed` instead of testing against it. PHPUnit reports exceptions of extensions as
  warnings, so `TemporalTestCase::setUp()` rethrows the start failure and the test errors; in a plain `TestCase` call
  `TemporalEnvironmentExtension::rethrowStartFailure()` in `setUp()` for the same behaviour.

#### Search attributes

Register custom search attributes on the dev server through the attribute, and let them through in test mode — by
default the bundle strips search attributes from every workflow start in test mode
(`pool.testing.disableSearchAttributes: true`):

```yaml
when@test:
  temporal:
    pool:
      testing:
        enabled: true
        disableSearchAttributes: false
```

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Workflow\GreetingWorkflow;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\SearchAttributes\SearchAttributeKey;
use Temporal\Common\SearchAttributes\ValueType;
use Temporal\Common\TypedSearchAttributes;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

#[WithTemporalEnvironment(searchAttributes: ['CustomerId' => ValueType::Keyword])]
final class CustomerSearchTest extends TemporalTestCase
{
    public function testFindsWorkflowByCustomer(): void
    {
        $workflow = $this->workflowClient->newWorkflowStub(
            GreetingWorkflow::class,
            WorkflowOptions::new()->withTypedSearchAttributes(
                TypedSearchAttributes::empty()->withValue(SearchAttributeKey::forKeyword('CustomerId'), 'c-1'),
            ),
        );

        $this->workflowClient->start($workflow, 'Vlad')->getResult('string', 10);

        // Visibility is eventually consistent: wait until the workflow can be found
        $query = "CustomerId = 'c-1'";

        for ($attempt = 0; $attempt < 50 && $this->workflowClient->countWorkflowExecutions($query)->count == 0; ++$attempt) {
            usleep(100_000);
        }

        self::assertSame(1, $this->workflowClient->countWorkflowExecutions($query)->count);
    }
}
```

Search attributes can only be registered by the dev server (`TemporalServerType::Dev`).

#### Temporal server options

Override how `temporal server start-dev` is started — the namespace, dynamic config values, or any other CLI flag:

```php
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;
use Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalTestCase;

#[WithTemporalEnvironment(
    temporalNamespace: 'billing',
    temporalDynamicConfig: ['frontend.enableUpdateWorkflowExecution' => true],
    temporalArguments: ['--log-level', 'warn'],
)]
abstract class BillingTestCase extends TemporalTestCase
{
}
```

- `temporalNamespace` is created when the server starts and is passed to RoadRunner (`-o temporal.namespace=…`), so the
  worker polls it. Point your application's client to the same namespace in the test environment — this is a global
  setting, so put the attribute on a shared base test class:

  ```yaml
  when@test:
    temporal:
      clients:
        default:
          namespace: billing
  ```

- `temporalDynamicConfig` becomes `--dynamic-config-value KEY=<json value>` per entry.
- `temporalArguments` accepts any `temporal server start-dev` flag (for example `['--db-filename', 'var/temporal.db']`);
  they are appended after the built-in `--log-level error --headless`, so a repeated flag overrides the built-in value.

These options apply to the dev server only (`TemporalServerType::Dev`).

### Testing workflows with Codeception

Steps 1–2 of the Quick start apply as well. Install Codeception
(`composer require --dev codeception/codeception codeception/module-symfony codeception/module-asserts`) and enable the
extension in `codeception.yml` — it starts and stops the environment for every Cest (or Codeception unit test) marked
with `#[WithTemporalEnvironment]`:

```yaml
extensions:
    enabled:
        - Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalEnvironmentExtension:
            logFile: var/log/test-environment.log   # optional
```

The suite needs the `Symfony` and `Asserts` modules plus a small helper module (below):

```yaml
actor: FunctionalTester
modules:
    enabled:
        - Symfony:
            app_path: 'src'
            environment: 'test'
        - Asserts
        - \App\Tests\Support\Helper\Temporal
```

Codeception does not load `.env.test` by itself: point it to the Symfony test bootstrap in `codeception.yml`
(`bootstrap: bootstrap.php`, i.e. `tests/bootstrap.php`) and run the suite with `APP_ENV=test`, so that both your
kernel and the RoadRunner worker see the test environment:

```bash
APP_ENV=test vendor/bin/codecept run Functional
```

`TemporalTestingTools` exposes the three services (`testService`, `activityMocker`, `workflowClient`) built from the
Codeception `Symfony` module; `reset()` returns a fresh instance. Hand it to your tests through the helper module
(run `vendor/bin/codecept build` after adding it):

```php
namespace App\Tests\Support\Helper;

use Codeception\Module;
use Codeception\Module\Symfony;
use Vanta\Integration\Symfony\Temporal\Testing\Codeception\TemporalTestingTools;

final class Temporal extends Module
{
    public function temporalTools(): TemporalTestingTools
    {
        /** @var Symfony $symfony */
        $symfony = $this->getModule('Symfony');

        return new TemporalTestingTools($symfony);
    }
}
```

and mark the Cest (or a single method) with the attribute:

```php
use App\Tests\Support\FunctionalTester;
use App\Workflow\GreetingWorkflow;
use Vanta\Integration\Symfony\Temporal\Testing\Attribute\WithTemporalEnvironment;

#[WithTemporalEnvironment]
final class GreetingWorkflowCest
{
    public function greet(FunctionalTester $I): void
    {
        $client   = $I->temporalTools()->workflowClient;
        $workflow = $client->newWorkflowStub(GreetingWorkflow::class);

        $I->assertSame('Hello, Vlad', $client->start($workflow, 'Vlad')->getResult('string', 10));
    }
}
```

### Reference: `Testing\Server`

| Class                     | Starts                                     | Main arguments (defaults)                                                                                                        |
|---------------------------|--------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------|
| `TemporalDevServer`       | `temporal server start-dev --headless`     | `binary` (`temporal`), `address` (`127.0.0.1:7233`), `namespace` (`default`), `searchAttributes` (`array<name, ValueType>`), `dynamicConfig`, `extraArguments` |
| `TemporalTestServer`      | `temporal-test-server` with time skipping  | `binary` (`temporal-test-server`), `port` (`7233`), `timeSkipping` (`true`)                                                      |
| `RoadRunnerServer`        | `rr serve -c <config> -w <dir>`            | `configFile`, `binary` (`rr`), `workingDirectory` (config dir), `env` (extra variables), `overrides` (`-o key=value`)            |
| `TemporalTestEnvironment` | the given servers in order                 | `Server ...$servers`, `withLogger(LoggerInterface)`                                                                              |

Every server also accepts `logFile`, `startTimeout`, `stopTimeout`, `binaryDirectories` (searched after `PATH` when
`binary` is a bare name) and a PSR-3 `logger`.

`TemporalDevServer` runs a full Temporal server. `TemporalTestServer` runs the Java test server that supports
time skipping, which `Temporal\Testing\TestService` needs. Download it explicitly when required:

```php
$binary = TemporalTestServer::download(__DIR__ . '/../var/bin', githubToken: null);
```

Behaviour:

- `start()` blocks until the server is ready (`temporal operator cluster health`, `rr workers`, or a TCP probe) and throws
  `Vanta\Integration\Symfony\Temporal\Testing\Server\ServerStartFailed` with the command and the tail of the server log
  when the binary or config is missing, the process exits, or `startTimeout` passes.
- `start()` also throws when something already answers on the server's address before it was started (for example a
  server left over from a killed run, or a Temporal container) instead of silently testing against it.
- `start()` and `stop()` are idempotent; servers that are still running when the PHP process exits are stopped by a
  shutdown hook, so an explicit `stop()` is optional.
- `TemporalTestEnvironment` starts servers in order and stops them all at once. If one fails to start, the already
  started servers are stopped and the original exception is rethrown.
- Server output goes to `logFile`, never to a pipe, so a chatty server cannot block. Without `logFile` it goes to a
  file in the system temp dir that is removed after a successful stop.
- `configFile` may be relative; it is resolved with `realpath()`.

Limitations:

- Ctrl+C (SIGINT) skips PHP shutdown functions; the servers still receive SIGINT from the terminal.
- Ports are fixed by your configuration, so `processIsolation` and paratest are not supported — each process would
  start its own servers.
- Linux and macOS only.

See `tests/E2e/E2eTestCase.php` for a base test case that uses the attribute with a custom RoadRunner config.
