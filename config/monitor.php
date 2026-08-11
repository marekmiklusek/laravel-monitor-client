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
