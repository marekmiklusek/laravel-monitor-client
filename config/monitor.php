<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return [

    /*
    |--------------------------------------------------------------------------
    | Monitoring Endpoint
    |--------------------------------------------------------------------------
    |
    | Base URL of the monitoring service and the bearer token used to
    | authenticate against it. Without a URL the package stays inert.
    |
    */

    'url' => env('MONITOR_URL'),

    'token' => env('MONITOR_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Activation
    |--------------------------------------------------------------------------
    |
    | The package only reports when it is enabled AND the current
    | environment is listed below. Off by default, so installing the
    | package never changes behaviour on its own.
    |
    */

    'enabled' => env('MONITOR_ENABLED', false),

    'environments' => ['production'],

    /*
    |--------------------------------------------------------------------------
    | Automatic Exception Hook
    |--------------------------------------------------------------------------
    |
    | When true the service provider attaches itself to the framework
    | exception handler automatically. Set it to false to register the
    | hook yourself in bootstrap/app.php via Monitor::handles().
    |
    */

    'auto_register' => true,

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    |
    | Seconds to wait for the monitoring service. Kept deliberately low:
    | a slow central must never slow down the host application.
    |
    */

    'timeout' => 2,

    /*
    |--------------------------------------------------------------------------
    | Request Input
    |--------------------------------------------------------------------------
    |
    | When enabled the exception context includes the request input (query
    | and body) and artisan command arguments, scrubbed and truncated.
    | Disable for applications with highly sensitive forms.
    |
    */

    'collect_input' => true,

    /*
    |--------------------------------------------------------------------------
    | Failed Jobs
    |--------------------------------------------------------------------------
    |
    | When enabled every JobFailed event is reported as a failed_job
    | occurrence, together with the job class, connection, queue, attempt
    | count and the scrubbed job payload.
    |
    */

    'collect_failed_jobs' => true,

    /*
    |--------------------------------------------------------------------------
    | Log Events
    |--------------------------------------------------------------------------
    |
    | Log records at or above this level are reported as log occurrences.
    | The package's own silenced failures are never collected, so a broken
    | monitoring service can not feed itself.
    |
    */

    'collect_logs' => true,

    'log_level' => 'error',

    /*
    |--------------------------------------------------------------------------
    | Buffer Limits
    |--------------------------------------------------------------------------
    |
    | The monitoring service accepts at most 100 occurrences and 512 KB per
    | request, so the buffer is flushed in batches bounded by both a count and
    | a serialised size, whichever is reached first. A single occurrence too
    | large on its own is truncated rather than dropped, and flagged with
    | truncated: true. Messages are always cut to max_message_length, so a
    | long record can never have the whole batch rejected with a 422. Once
    | the buffer holds max_buffered_occurrences the client stops collecting
    | and counts what it drops; the count is reported as a warning occurrence
    | in the last batch, so the loss is visible centrally and not only in the
    | local log. Exceptions and failed jobs take priority over log events: a
    | full buffer gives up its oldest log event rather than lose a crash.
    |
    */

    'max_occurrences_per_request' => 100,

    'max_payload_kilobytes' => 400,

    'max_message_length' => 8000,

    'max_buffered_occurrences' => 200,

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    |
    | A circular buffer of the last N log records of any level. It is only
    | ever sent as part of an exception or failed job; when nothing fails,
    | the buffer is thrown away.
    |
    */

    'collect_breadcrumbs' => true,

    'breadcrumbs_limit' => 30,

    /*
    |--------------------------------------------------------------------------
    | Failure Logging
    |--------------------------------------------------------------------------
    |
    | Silenced failures are written to this log channel as warnings, at most
    | once per throttle window for each failure type. A null channel means
    | the application default.
    |
    */

    'log_channel' => null,

    'log_throttle_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Ignored Exceptions
    |--------------------------------------------------------------------------
    |
    | Exceptions matched with instanceof, so subclasses are ignored too.
    |
    */

    'ignored_exceptions' => [
        NotFoundHttpException::class,
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrubbed Keys
    |--------------------------------------------------------------------------
    |
    | Keys whose values are replaced with [REDACTED] before leaving the
    | application. Matched case insensitively, at any nesting depth.
    |
    */

    'scrub_keys' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'authorization',
        'api_key',
        'credit_card',
    ],

];
