# Laravel Monitor Client

Laravel client that reports uncaught exceptions and periodic heartbeats to a
self-hosted monitoring service. The server side lives at
[marekmiklusek/monitor](https://github.com/marekmiklusek/monitor) – install
that first, then point this client at it via `MONITOR_URL`.

The package is built around a single rule: **it must never break or slow down
the host application**. Every network call, every serialisation step and even
the package boot itself is wrapped in a `try/catch` that fails silently. A lost
report is always preferable to a 500 in production. Silenced failures are not
invisible, though – they are written to the application log (see
[Failure logging](#failure-logging)).

## Requirements

- PHP 8.4+
- Laravel 13

## Installation

```bash
composer require marekmiklusek/laravel-monitor-client
```

Publish the config file:

```bash
php artisan vendor:publish --tag=monitor-config
```

### Local development with a path repository

To work on the package and a host application at the same time, point Composer
at your local checkout. With `"symlink": true` the package directory is
symlinked into `vendor/`, so edits are picked up immediately without reinstalling.

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-monitor-client",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "marekmiklusek/laravel-monitor-client": "*"
    }
}
```

## Configuration

All settings are read through `config('monitor.*')`. The package never calls
`env()` outside of `config/monitor.php`, so it keeps working under
`php artisan config:cache`.

| Env variable      | Config key         | Default        | Description                                       |
|-------------------|--------------------|----------------|---------------------------------------------------|
| `MONITOR_URL`     | `monitor.url`      | `null`         | Endpoint that receives the payload                |
| `MONITOR_TOKEN`   | `monitor.token`    | `null`         | Sent as `Authorization: Bearer <token>`           |
| `MONITOR_ENABLED` | `monitor.enabled`  | `false`        | Master switch, off by default                     |

```dotenv
MONITOR_ENABLED=true
MONITOR_URL=https://monitor.example.com/api/events
MONITOR_TOKEN=your-token-here
```

Config-only options (no env variable):

| Key                          | Default                                                                                       | Description                                            |
|------------------------------|-----------------------------------------------------------------------------------------------|--------------------------------------------------------|
| `monitor.environments`       | `['production']`                                                                              | Environments the package is active in                  |
| `monitor.timeout`            | `2`                                                                                           | HTTP timeout in seconds, no retries                    |
| `monitor.auto_register`      | `true`                                                                                        | Hook into the exception handler automatically          |
| `monitor.collect_input`      | `true`                                                                                        | Collect request input and command arguments into the context |
| `monitor.log_channel`        | `null`                                                                                        | Channel for silenced-failure warnings, `null` = default |
| `monitor.log_throttle_minutes` | `5`                                                                                         | Same failure type is logged at most once per window    |
| `monitor.ignored_exceptions` | `NotFoundHttpException`, `ValidationException`, `AuthenticationException`, `AuthorizationException` | Matched with `instanceof`, so subclasses are ignored too |
| `monitor.scrub_keys`         | `password`, `password_confirmation`, `token`, `secret`, `authorization`, `api_key`, `credit_card` | Replaced with `[REDACTED]`, case insensitive, at any depth |

**Nothing is collected at all** unless `monitor.enabled` is true, a URL is set,
and the current environment is listed in `monitor.environments`. Installing the
package therefore never changes behaviour on its own.

### Verify the installation

After setting the `MONITOR_` variables, confirm the application can actually
reach the monitoring service:

```bash
php artisan monitor:test
```

The command sends one test occurrence and prints the result **loudly** – it is the
only place in the package where errors are not silenced. On success it prints
the HTTP status; on failure it prints the concrete reason (connection error,
status code, response body). It runs even when `monitor.enabled` is false, so
it can be used before switching the package on. A non-zero exit code makes it
usable in deploy scripts.

After a successful send the command also prints whether **live reporting** is
active: if the package is disabled or the current environment is not listed in
`monitor.environments`, it warns that connectivity is OK but real exceptions
are not being reported. Connectivity and live collection are two different
things – a green test does not mean exceptions are flowing.

### Heartbeats require the scheduler

The package registers the heartbeat to run **every 5 minutes** through the
Laravel scheduler automatically – there is nothing to add to your own
schedule. The host project therefore **must** have the scheduler running:

```cron
* * * * * php artisan schedule:run
```

If the scheduler is not running, no heartbeats are sent and the monitoring
service will falsely report the project as down.

Verify that the heartbeat is registered:

```bash
php artisan schedule:list
```

A side effect worth knowing: because heartbeats go through the scheduler,
they implicitly monitor your cron as well. If cron dies on the server,
heartbeats stop and the monitoring service raises an alert.

### Failure logging

Every silenced failure – a rejected or failed HTTP request, a serialisation
error, a broken boot – is written to the log as a `warning`:

- channel comes from `monitor.log_channel` (`null` = application default),
- the same failure type is logged at most once per `monitor.log_throttle_minutes`
  (default 5), so an unreachable monitoring service cannot flood the log,
- if the cache is unavailable, throttling is skipped and the warning is logged
  anyway,
- while a warning is being written the package collects nothing, so a failure
  inside logging can never loop back into the reporter.

If exceptions stop arriving at the central service, check the host
application's log for `Laravel Monitor client` warnings first, then run
`php artisan monitor:test`.

## How it works

Exceptions are collected into an in-memory buffer during a request and shipped
in a **single** HTTP request from a `terminating` callback. In contexts where
`terminating` is not reliable, the buffer is flushed on:

- `CommandFinished` – end of an Artisan command
- `JobExceptionOccurred` – every failed attempt, not just the final one
- `JobProcessed` / `JobFailed` – end of a queued job
- `WorkerStopping` – `queue:restart`, so a partial buffer still ships

The buffer is keyed by the throwable instance itself (`SplObjectStorage`), so
the same exception reported twice only ever produces one occurrence.

### Registering the exception hook

By default the service provider attaches itself to the framework exception
handler and there is nothing to do.

If you prefer to wire it up explicitly, set `monitor.auto_register` to `false`
and register it in `bootstrap/app.php`. Note the explicit import – the facade is
deliberately **not** registered as a global `\Monitor` alias, to avoid colliding
with a class of the same name in your application.

```php
use MarekMiklusek\MonitorClient\Facades\Monitor;

return Application::configure(basePath: dirname(__DIR__))
    ->withExceptions(function (Exceptions $exceptions): void {
        Monitor::handles($exceptions);
    })
    ->create();
```

Calling `Monitor::handles()` while auto-registration is still on is harmless:
an internal flag guarantees a single registration regardless of call order, so
no exception is ever reported twice.

> **Exceptions listed in your application's `dontReport` never reach the
> monitoring service.** The package hooks into the reportable chain, which the
> framework skips entirely for ignored exception types. If you need to see one
> of those centrally, remove it from `dontReport` and add it to
> `monitor.ignored_exceptions` instead – or leave it out of both.

### Heartbeat

The service provider schedules `monitor:heartbeat` every five minutes, but only
when the package is enabled for the current environment. Make sure the host
application runs the scheduler.

You can also send one by hand:

```bash
php artisan monitor:heartbeat
```

## Payload

```json
{
  "schema_version": 1,
  "sent_at": "2026-08-06T12:34:56+00:00",
  "environment": "production",
  "occurrences": [
    {
      "type": "exception",
      "occurred_at": "2026-08-06T12:34:55+00:00",
      "exception_class": "RuntimeException",
      "message": "Something went wrong",
      "file": "/app/Http/Controllers/OrderController.php",
      "line": 42,
      "stack": [
        {
          "file": "/app/Http/Controllers/OrderController.php",
          "line": 42,
          "function": "store",
          "class": "App\\Http\\Controllers\\OrderController"
        }
      ],
      "context": {
        "url": "https://example.com/orders",
        "method": "POST",
        "user_id": 1,
        "headers": {
          "accept": "application/json",
          "user-agent": "Mozilla/5.0"
        },
        "input": {
          "email": "customer@example.com",
          "password": "[REDACTED]",
          "attachment": "[FILE invoice.pdf, 48211 B]"
        }
      }
    }
  ]
}
```

Stack traces are truncated to 30 frames. Frame **arguments are never sent** –
they routinely contain passwords and tokens and cannot be serialised reliably.

### Adding new occurrence types

`type` is backed by the `OccurrenceType` enum, and every occurrence is a
subclass of `MonitorOccurrence` that renders its own `payload()`. Failed jobs,
slow queries or breadcrumbs are added by introducing a new enum case and a new
subclass – the buffer, the payload envelope and the transport stay untouched.

Besides `url`, `method` and `user_id`, the context carries the request
`input` (query + body, max depth 3, max 100 keys, values truncated to
1000 characters, uploads replaced with a `[FILE <name>, <size> B]`
placeholder) and a whitelist of `headers` (`accept`, `content-type`,
`user-agent`, `referer`, `origin`, `x-request-id`). In console context
`input` is replaced by `command` with the artisan command name and its
arguments. Set `monitor.collect_input` to `false` to collect neither
input nor command arguments.

## Security

Values of keys listed in `monitor.scrub_keys` are replaced with `[REDACTED]`
before anything leaves the application. Matching is case insensitive and
recursive through the whole context, including request input and command
arguments.

The `authorization` and `cookie` headers are **never collected** – not even
in redacted form, they simply are not read. Uploaded files are represented
only by name and size; their content never leaves the application.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
