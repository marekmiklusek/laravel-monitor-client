<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Http\Client\ConnectionException;

it('logs a failed response with the status and a truncated body', function (): void {
    Http::fake([
        '*' => Http::response(str_repeat('x', 600), 500),
    ]);

    $logs = capturedLogs();

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->level)->toBe('warning')
        ->and($logs[0]->message)->toBe('Laravel Monitor client request was rejected.')
        ->and($logs[0]->context['status'])->toBe(500)
        ->and(mb_strlen($logs[0]->context['body']))->toBe(500);
});

it('throttles repeated failed responses of the same status', function (): void {
    Http::fake([
        '*' => Http::response('rejected', 500),
    ]);

    $logs = capturedLogs();

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('first'));
    $monitor->flush();

    $monitor->report(new RuntimeException('second'));
    $monitor->flush();

    Http::assertSentCount(2);

    expect($logs)->toHaveCount(1);
});

it('logs a connection failure', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('Could not resolve host');
    });

    $logs = capturedLogs();

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('Laravel Monitor client request failed.')
        ->and($logs[0]->context['exception'])->toBe(ConnectionException::class);
});

it('does not buffer exceptions raised while a failure is being logged', function (): void {
    Http::fake([
        '*' => Http::response('rejected', 500),
    ]);

    $monitor = app(Monitor::class);

    Event::listen(MessageLogged::class, function () use ($monitor): void {
        $monitor->report(new RuntimeException('raised inside the log write'));
    });

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);
});

it('swallows a 500 response from the monitoring service', function (): void {
    Http::fake([
        '*' => Http::response('server exploded', 500),
    ]);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSentCount(1);

    expect($monitor->bufferCount())->toBe(0);
});

it('swallows a connection exception', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('Could not resolve host');
    });

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();
})->throwsNoExceptions();

it('swallows a connection exception during a heartbeat', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('Could not resolve host');
    });

    app(Monitor::class)->heartbeat();
})->throwsNoExceptions();

it('still clears the buffer when sending fails', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('Could not resolve host');
    });

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);
});

it('swallows a malformed url', function (): void {
    Http::fake();

    config()->set('monitor.url', 'not-a-url');

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();
})->throwsNoExceptions();
