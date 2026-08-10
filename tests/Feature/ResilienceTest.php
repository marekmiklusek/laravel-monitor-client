<?php

declare(strict_types=1);

use Tests\Fakes\FailingTransport;
use Tests\Fakes\RecordingTransport;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Console\Events\CommandFinished;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Illuminate\Foundation\Configuration\Exceptions;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;

beforeEach(function (): void {
    Http::fake();
});

function monitorWith(Transport $transport): Monitor
{
    return new Monitor(
        config: app(MonitorConfig::class),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );
}

it('swallows a transport that throws while flushing', function (): void {
    $monitor = monitorWith(new FailingTransport);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);
});

it('swallows a transport that throws during a heartbeat', function (): void {
    monitorWith(new FailingTransport)->heartbeat();
})->throwsNoExceptions();

it('keeps reporting after a failed flush', function (): void {
    $monitor = monitorWith(new FailingTransport);

    $monitor->report(new RuntimeException('first'));
    $monitor->flush();

    $monitor->report(new RuntimeException('second'));

    expect($monitor->bufferCount())->toBe(1);
});

it('hands the whole payload to the transport', function (): void {
    $transport = new RecordingTransport;

    $monitor = monitorWith($transport);

    $monitor->report(new RuntimeException('recorded'));
    $monitor->flush();

    expect($transport->payloads)->toHaveCount(1)
        ->and($transport->payloads[0]['occurrences'][0]['message'])->toBe('recorded');
});

it('registers through handles() and reports through the callback', function (): void {
    $transport = new RecordingTransport;
    $monitor = monitorWith($transport);

    expect($monitor->hasRegisteredHandler())->toBeFalse();

    $handler = app(FoundationHandler::class);
    $monitor->handles(new Exceptions($handler));

    expect($monitor->hasRegisteredHandler())->toBeTrue();

    $handler->report(new RuntimeException('through handles'));

    expect($monitor->bufferCount())->toBe(1);
});

it('ignores a second handles() call', function (): void {
    $monitor = app(Monitor::class);

    expect($monitor->hasRegisteredHandler())->toBeTrue();

    $monitor->handles(new Exceptions(app(FoundationHandler::class)));

    expect($monitor->hasRegisteredHandler())->toBeTrue();
});

it('flushes the buffer when a console command finishes', function (): void {
    $monitor = app(Monitor::class);
    $monitor->report(new RuntimeException('from a command'));

    app(Dispatcher::class)->dispatch(new CommandFinished(
        'monitor:test',
        new ArrayInput([]),
        new NullOutput,
        0,
    ));

    expect($monitor->bufferCount())->toBe(0);

    Http::assertSentCount(1);
});

it('flushes the buffer when the queue worker stops', function (): void {
    $monitor = app(Monitor::class);
    $monitor->report(new RuntimeException('from a worker'));

    app(Dispatcher::class)->dispatch(new WorkerStopping);

    expect($monitor->bufferCount())->toBe(0);

    Http::assertSentCount(1);
});

it('flushes the buffer on application termination', function (): void {
    $monitor = app(Monitor::class);
    $monitor->report(new RuntimeException('from a request'));

    app()->terminate();

    expect($monitor->bufferCount())->toBe(0);

    Http::assertSentCount(1);
});

it('registers the heartbeat on the schedule', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'monitor:heartbeat')))
        ->toBeTrue();
});
