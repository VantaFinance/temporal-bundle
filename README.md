![Temporal Bundle](./assets/temporal-symfony.svg)


# Temporal Bundle

`vanta/temporal-bundle` connects [Temporal](https://temporal.io/) to Symfony through the
[PHP SDK](https://github.com/temporalio/sdk-php) and [RoadRunner](https://roadrunner.dev/).
Declare workflows and activities as Symfony services, configure workers, and inspect client calls in the web profiler.

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
- [Temporal migrations](#temporal-migrations)
- [Replaying workflows](#replaying-workflows)
- [Debug commands](#debug-commands)
- [Buggregator](#buggregator)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

## Features

- Register workflows, activities, clients, and workers through Symfony services and attributes.
- Convert payloads with Symfony Serializer; extend clients and workers with SDK plugins and interceptors.
- Reset services after activities; integrate Doctrine cleanup, transaction reporting, and Sentry.
- Inspect registrations and client calls with console commands and the Symfony profiler; replay workflow histories.
- Test with PHPUnit or Codeception, activity mocks, and explicit Temporal/RoadRunner lifecycle control.

## Requirements

- PHP >= 8.2
- Symfony >= 6.4 (`^6.4 || ^7 || ^8`)
- temporal/sdk >= 2.16 with a compatible RoadRunner version:

| temporal/sdk  | RoadRunner server | RoadRunner PHP packages              | Notes                                               |
|---------------|-------------------|--------------------------------------|-----------------------------------------------------|
| 2.16 – 2.19   | 2025.x            | `spiral/roadrunner*`                 |                                                     |
| 2.16 – 2.19   | 3.x               | `spiral/roadrunner*`                 | works; every worker logs a version-checker warning  |
| >= 2.20       | 3.x               | `roadrunner/*` (replace `spiral/*`)  | needs Carbon 3                                      |

Options marked `SDK >= x` require that temporal/sdk version. Setting one on an older SDK fails the container build
with the required version in the error message. Unset options are not passed to the SDK.

## Installation

1. Add the recipe endpoint

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

The default client (`defaultClient`) is autowired as `Temporal\Client\WorkflowClientInterface`. Named clients are
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

The task queue must match the worker's `taskQueue`. `start()` returns once Temporal accepts the workflow.
Call `getResult()` only if the caller needs to wait for completion.

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

Apply the attribute to a workflow or activity class. Repeat it to register that class on both workers:

```php
use Vanta\Integration\Symfony\Temporal\Attribute\AssignWorker;

#[AssignWorker('default')]
#[AssignWorker('worker1')]
// Your existing workflow or activity class follows.
```

## Doctrine integrations

```bash
composer require orm temporal-doctrine
```

With [DoctrineBundle](https://github.com/doctrine/DoctrineBundle), configure cleanup and transaction reporting
globally under `pool` or for an individual worker:

| All workers (`pool`) | One worker (`workers.<name>`) | Value |
| --- | --- | --- |
| `useGlobalDoctrineIntegration` | `useDoctrineIntegration` | Entity-manager names to clear and check |
| `useGlobalLoggingDoctrineOpenTransaction` | `useLoggingDoctrineOpenTransaction` | Connection names for Monolog transaction reports |
| `useGlobalTrackingSentryDoctrineOpenTransaction` | `useTrackingSentryDoctrineOpenTransaction` | Connection names for Sentry transaction reports |

For all workers:

```yaml
temporal:
  pool:
    useGlobalDoctrineIntegration: [default]
    useGlobalLoggingDoctrineOpenTransaction: [default]
```

Or for one worker:

```yaml
temporal:
  workers:
    default:
      taskQueue: default
      useDoctrineIntegration: [default]
      useLoggingDoctrineOpenTransaction: [default]
```

## Sentry integrations

```bash
composer require sentry temporal-sentry
```

With [SentryBundle](https://github.com/getsentry/sentry-symfony), enable exception reporting for all workers:

```yaml
temporal:
  pool:
    useGlobalSentryIntegration: true
```

Or enable it for an individual worker:

```yaml
temporal:
  workers:
    default:
      taskQueue: default
      useSentryIntegration: true
```

## HTTP with roadrunner-symfony/http-bundle

[roadrunner-symfony/http-bundle](https://github.com/roadrunner-symfony/http-bundle) serves HTTP requests from the same
application. HTTP and Temporal run in two RoadRunner processes, each with its own config and runtime:

| Config              | `server.env.APP_RUNTIME`                                     |
|---------------------|--------------------------------------------------------------|
| `.rr.http.yaml`     | `Roadrunner\Integration\Symfony\Http\Runtime\HttpRuntime`        |
| `.rr.temporal.yaml` | `Vanta\Integration\Symfony\Temporal\Runtime\TemporalRuntime`     |

Both processes start `php public/index.php`. `HttpRuntime` serves HTTP requests; `TemporalRuntime` polls Temporal.
Install both bundles through Flex with both recipe endpoints:

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

The recipe files `.rr.temporal.yaml` and `.rr.temporal.testing.yaml` work on both versions. When upgrading your own
configuration to RoadRunner 3, check the following settings:

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

Each client and schedule client has its own connection:

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

With `tls.enabled: ~` (the default), configuring `apiKey`, a certificate, or `serverName` enables TLS.
TLS is selected at container build time: an `apiKey` env reference enables it even if the variable resolves to an empty
value. Set `tls.enabled: false` for a local server without TLS.

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

When `envConfig.enabled` is set, the profile supplies `address`, `apiKey` and `tls`; these options cannot be set explicitly.
The SDK reads `TEMPORAL_*` variables with `getenv()`, so they must be real process env variables; values from `.env` files are seen
only when Symfony Dotenv runs with `usePutenv`. Reading a TOML file needs `composer require internal/toml`.

## Worker options

Configure worker behavior under `workers.<name>`:

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

Flags remain set for the lifetime of the PHP process, including other kernels booted in the same PHPUnit run.

## Plugins

SDK >= 2.18. The bundle registers every service that implements `Temporal\Plugin\PluginInterface` (for example by
extending `Temporal\Plugin\AbstractPlugin`) in every workflow client, schedule client and the worker factory.
Each consumer has a separate plugin registry, preventing duplicate plugin names in the SDK.

Use `#[AsPlugin]` to select clients and the worker factory. `workers: true` applies to the shared factory, not a named
worker; `scheduleClients` accepts schedule-client names. An empty attribute keeps global registration.
This example selects the `billing` client and the worker factory:

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

To register plugins through configuration:

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

By default, `temporal.data_converter` serializes payloads to JSON with Symfony Serializer. On SDK >= 2.20,
normalizers receive the Temporal namespace and workflow ID under `temporal_serialization_context` in the serializer
context.

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
For an interceptor created by a factory, set the service's `class` so the bundle can identify its type.

## Additional clients

Each workflow client `<name>` also provides:

| Autowiring                                                                          | SDK      |
|-------------------------------------------------------------------------------------|----------|
| `Temporal\Client\ActivityCompletionClientInterface $<name>ActivityCompletionClient` |          |
| `Temporal\Client\GRPC\OperatorClientInterface $<name>OperatorClient`                | >= 2.18  |

For the default client, these services are autowired by interface alone unless the application already defines a
service with that ID.

SDK >= 2.19 adds a client for the Temporal Cloud Ops API, autowired as `Temporal\Client\GRPC\CloudClientInterface`:

```yaml
temporal:
  cloudClient:
    enabled: true
    apiKey: '%env(TEMPORAL_CLOUD_API_KEY)%'   # or "@service_id"
    # address: saas-api.tmprl.cloud:443
    # apiVersion: '2024-10-01-00'
```

## Temporal migrations

Manage namespaces and search attributes with PHP migrations. Requires SDK >= 2.18.
Every run executes all migration files; execution history is not stored.

Enable migrations and choose a directory:

```yaml
# config/packages/temporal.yaml
temporal:
  migrations:
    enabled: true
    paths:
      'App\TemporalMigrations': '%kernel.project_dir%/temporal-migrations'
    # client: default  # Defaults to temporal.defaultClient
    # timeout: 60      # Seconds per migration method
```

Migrations use the selected client's connection and default Temporal namespace.
Configure that client directly; `envConfig` profiles are unsupported for migrations.

Generate a migration:

```bash
php bin/console temporal:migrations:generate 'Create billing attributes'
```

The command creates the directory and a `MigrationYYYYMMDDHHMMSS.php` file.
With multiple directories, pass `--namespace='App\TemporalMigrations'` to choose the PHP namespace.

Implement `Migration` with `up()` and `down()`:

```php
<?php

declare(strict_types=1);

namespace App\TemporalMigrations;

use DateInterval;
use Vanta\Integration\Symfony\Temporal\Migration\Migration;
use Vanta\Integration\Symfony\Temporal\Migration\Scheme;

final class Migration20261107000000 implements Migration
{
    public function up(Scheme $scheme): void
    {
        $scheme->namespace('billing')
            ->description('Billing workflows')
            ->ownerEmail('platform@example.com')
            ->data(['team' => 'billing'])
            ->retention(new DateInterval('P7D'))
            ->ensure();

        $scheme->searchAttributes('billing')
            ->keyword('CustomerId')
            ->integer('OrderNumber')
            ->apply();
    }

    public function down(Scheme $scheme): void
    {
        $scheme->searchAttributes('billing')
            ->remove('OrderNumber')
            ->remove('CustomerId')
            ->apply();
    }
}
```

`ensure()` creates a namespace or updates its declared settings. Creating one requires retention.
Omitted settings and undeclared `data` keys stay unchanged. Description and owner email must be nonempty.
Use fixed retention intervals such as `P7D` or `PT24H`; years and months are unsupported.

Search attributes support `text()`, `keyword()`, `keywordList()`, `integer()`, `double()`,
`boolean()`, `datetime()`, and `remove()`. Finish declarations with `apply()`.
Existing attributes with the requested type and already absent removals need no changes.
Use a new name to change an attribute's type.

Omit the namespace argument to use the client's default. Each builder selects its own namespace.
For other administration operations, use `$scheme->serviceClient()` or `$scheme->operatorClient()`.
Explicit namespace deletion is available through `$scheme->namespace('billing')->delete()` and can destroy workflow data.

Run migrations:

```bash
php bin/console temporal:migrations:migrate
php bin/console temporal:migrations:migrate up --no-interaction
php bin/console temporal:migrations:migrate down --no-interaction
```

`up` runs all files in timestamp order; `down` runs them in reverse.
Write both directions so the entire set can run repeatedly, including any raw client calls.
On failure, execution stops and earlier changes remain. Fix the cause, then rerun.
There is no automatic rollback; `down` performs only the actions you write and can remove pre-existing attributes.
Run one migration command at a time for each Temporal target.

With SQL visibility, custom attributes belong to a namespace. Elasticsearch shares them across an index,
so removing one can affect other namespaces. Temporal Cloud provisioning uses a separate API.

## Replaying workflows

`temporal:workflow:replay` replays a workflow history against your current workflow code. It exits with code 1 when the
history is not deterministic. The replay runs inside RoadRunner, so start RoadRunner with your workers first; the
command connects to `RR_RPC` or `pool.roadrunnerRPC`.

```bash
bin/console temporal:workflow:replay OrderWorkflow --workflow-id=order-42 --run-id=YOUR_RUN_ID
bin/console temporal:workflow:replay OrderWorkflow --history-file=var/history.json --last-event-id=12
```

Choose exactly one history source. For server replay on SDK 2.20, supply the execution's `--run-id` explicitly.
`--last-event-id` is optional for file replay. The history file must also be readable by RoadRunner at the same path.

## Debug commands

Use these commands to check service registration and worker assignment:

| Command | Description |
| --- | --- |
| `debug:temporal:workers` | Workers, SDK/RoadRunner versions, feature flags, and plugins |
| `debug:temporal:workflows` | Workflows grouped by worker |
| `debug:temporal:activities` | Activities grouped by worker |
| `debug:temporal:clients` | Workflow clients and connection configuration |
| `debug:temporal:schedule-clients` | Schedule clients and connection configuration |

Each command accepts names to filter its output; omit them to show all registrations:

```bash
bin/console debug:temporal:workers
bin/console debug:temporal:workers default test
bin/console debug:temporal:clients default
```

Connection diagnostics show TLS/API-key configuration without printing keys or certificates.

### Buggregator

The development Compose stack includes [Buggregator](https://buggregator.dev/) 2.6.0 for dumps, logs, and captured mail.
Start it independently:

```bash
docker compose -f .docker/docker-compose-temporal.yml up -d --no-deps buggregator
```

Open http://localhost:8000. Temporal UI stays on port 8080. The PHP worker has
`VAR_DUMPER_FORMAT=server` and `VAR_DUMPER_SERVER=buggregator:9912`, so Symfony's default dump handler sends
`dump()` output to Buggregator. If your application installs its own handler, configure its destination separately.
Run dumps in activities or application code; workflow execution must stay deterministic.

For a PHP process running on the host:

```bash
VAR_DUMPER_FORMAT=server VAR_DUMPER_SERVER=127.0.0.1:9912 \
  php -r 'require "vendor/autoload.php"; dump(["message" => "Hello from Symfony"]);'
```

| Endpoint | Inside Compose | From the host | Host-port override |
| --- | --- | --- | --- |
| Web UI / HTTP | `http://buggregator:8000` | `http://localhost:8000` | `BUGGREGATOR_HTTP_PORT` |
| VarDumper | `buggregator:9912` | `127.0.0.1:9912` | `BUGGREGATOR_VAR_DUMPER_PORT` |
| Monolog TCP | `buggregator:9913` | `127.0.0.1:9913` | `BUGGREGATOR_MONOLOG_PORT` |
| SMTP | `buggregator:1025` | `127.0.0.1:1025` | `BUGGREGATOR_SMTP_PORT` |

Set the port variables in the shell or Compose `.env` file if a port is busy. Internal ports stay unchanged.
Monolog and mail delivery need their own application configuration; adding the service does not reroute them.
Buggregator loses captured data on restart. Check startup errors with:

```bash
docker compose -f .docker/docker-compose-temporal.yml logs buggregator
```

## Troubleshooting

**A workflow or activity is not registered** (`ActivityNotRegisteredError`, unknown workflow type). Run
`bin/console debug:temporal:workflows` and `bin/console debug:temporal:activities` to see which worker has which class.
The class needs `#[WorkflowInterface]` or `#[ActivityInterface]` and must be an autoconfigured service. The worker is
selected by `#[AssignWorker]` or, when the attribute is absent, by `defaultWorker` (see [Assign worker](#assign-worker)).
Start the workflow with that worker's `taskQueue`.

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

Enable bundle test services with `pool.testing.enabled`; start processes explicitly with
`#[WithTemporalEnvironment]` or the [server classes](#reference-testingserver). Enabling test services alone does not
start Temporal or RoadRunner.

`TemporalTestCase` provides `$workflowClient`, `$activityMocker`, and `$testService`. Codeception's
`TemporalTestingTools` provides the same services. Both expose `getWorkflowMocker()` and `getSearchAttributeMocker()`
on SDK >= 2.18. The PHPUnit and Codeception `TemporalEnvironmentExtension` integrations manage marked tests.

### Quick start

1. Install PHPUnit before the bundle so its recipe can configure testing.
[`dload`](https://github.com/php-internal/dload) downloads binaries to the project root:

```bash
composer require --dev symfony/test-pack internal/dload
vendor/bin/dload get rr temporal
```

Then install the bundle as described in [Installation](#installation).

2. Check these recipe settings; add missing parts manually if PHPUnit was installed later or your files use other names:

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

- Copy [`.rr.temporal.testing.yaml`](.rr.temporal.testing.yaml) to the project root. Its `kv.test` memory storage
  is required by the default `rr_kv` activity mocker. The RoadRunner RPC listener must match `RR_RPC`.

- `phpunit.dist.xml` (or `phpunit.xml.dist`) — the extension, inside the `<extensions>` block that Symfony's PHPUnit
  recipe creates. The optional `logFile` records environment starts and stops:

  ```xml
  <extensions>
      <bootstrap class="Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension">
          <parameter name="logFile" value="var/log/test-environment.log"/>
      </bootstrap>
  </extensions>
  ```

3. Write a workflow and a test. `src/Workflow/GreetingActivity.php`:

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

`tests/GreetingWorkflowTest.php` (the attribute applies to every test in the class):

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

The attribute finds `temporal` and `rr` in `PATH` or the project root and uses `.rr.temporal.testing.yaml` from that root.
The RoadRunner worker inherits `APP_ENV=test` and the `.env.test` values from PHPUnit, and boots the kernel in the
`test` environment.

4. Run it:

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

SDK >= 2.18 supports mocks for child workflows, side effects and search attribute updates in test mode.
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

Test-mode settings:

- `pool.testing.enabled` — activate test mode
- `pool.testing.activityMocker` — which ActivityMocker to use. Default value: `rr_kv`. Allowed values:
  `rr_kv`, `in_memory`, or a service id if you want to use your own implementation of
  `Temporal\Worker\ActivityInvocationCache\ActivityInvocationCacheInterface` (SDK >= 2.18 added
  `saveConsecutiveCompletions()` and `saveCompletionWhen()` to it)
- `pool.testing.activityCacheName` — the RoadRunner KV storage of the `rr_kv` mocks. Default value: `test`; it must
  match a `kv.<name>` section of the RoadRunner config
- `pool.testing.testServices.<name>` — list of configured `Temporal\Testing\TestService`
- `pool.testing.timeLocking` — SDK >= 2.18. While a workflow client waits for a result, the time-skipping test server
  (`TemporalServerType::TimeSkipping`) does not skip time
- `pool.testing.transcript` — SDK >= 2.18. Path of a file where the workers write every workflow and activity call

### Lifecycle per test

Apply `#[WithTemporalEnvironment]` to a test class to cover every test, including subclasses, or to a method to
override the class settings. All arguments are optional:

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

The extension starts servers before `setUp()` and stops them after `tearDown()`. It works with `TemporalTestCase`
or a plain PHPUnit `TestCase`. In a plain test, rethrow startup failures from `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();
    TemporalEnvironmentExtension::rethrowStartFailure();
}
```

Import `Vanta\Integration\Symfony\Temporal\Testing\PHPUnit\TemporalEnvironmentExtension`.
Its `current()` method returns the running environment, or `null` without the attribute.
PHPUnit reports extension startup exceptions as warnings; rethrowing them makes the test fail.
`TemporalTestCase` already rethrows startup failures. See [Codeception setup](#testing-workflows-with-codeception).

For manual lifecycle control, omit the attribute and start the environment in your test hooks:

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

Ports must be free: a server already answering at the configured address causes `ServerStartFailed`.
Each marked test starts fresh processes; budget for startup and shutdown overhead.

#### Search attributes

The bundle strips search attributes from workflow starts in test mode by default
(`pool.testing.disableSearchAttributes: true`). Disable that behavior to test them:

```yaml
when@test:
  temporal:
    pool:
      testing:
        enabled: true
        disableSearchAttributes: false
```

Register `CustomerId` on the dev server with
`#[WithTemporalEnvironment(searchAttributes: ['CustomerId' => ValueType::Keyword])]`, importing
`Temporal\Common\SearchAttributes\ValueType`. Set it when creating the workflow stub:

```php
use Temporal\Client\WorkflowOptions;
use Temporal\Common\SearchAttributes\SearchAttributeKey;
use Temporal\Common\TypedSearchAttributes;

$options = WorkflowOptions::new()->withTypedSearchAttributes(
    TypedSearchAttributes::empty()->withValue(SearchAttributeKey::forKeyword('CustomerId'), 'c-1'),
);
```

Search-attribute registration is available only in `Dev` mode. Visibility queries are eventually consistent;
poll when asserting on them.

#### Temporal server options

Set the namespace, dynamic configuration, or other CLI flags for `temporal server start-dev`:

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

Enable the `Symfony` and `Asserts` modules and the helper defined below:

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

`TemporalTestingTools` uses the Codeception `Symfony` module; `reset()` returns a fresh instance.
Expose it through a helper, then run `vendor/bin/codecept build`:

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

Mark the Cest or a method with the attribute:

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
- Server output is written to `logFile` rather than a pipe, so output cannot fill a pipe buffer and block the server.
  When `logFile` is omitted, output goes to a system temp file removed after a successful stop.
- `configFile` may be relative; it is resolved with `realpath()`.

Limitations:

- Ctrl+C (SIGINT) skips PHP shutdown functions; the servers still receive SIGINT from the terminal.
- Ports are fixed by your configuration, so `processIsolation` and paratest are not supported — each process would
  start its own servers.
- Linux and macOS only.
