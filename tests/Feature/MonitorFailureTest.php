<?php

declare(strict_types=1);

use Tests\Fakes\FailingExceptions;
use Tests\Fakes\RecordingTransport;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use Tests\Fakes\FailingConfigRepository;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;

beforeEach(function (): void {
    Http::fake();
});

it('swallows an exception handler that refuses registration', function (): void {
    $monitor = new Monitor(
        config: app(MonitorConfig::class),
        transport: new RecordingTransport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );

    $monitor->handles(new FailingExceptions(app(FoundationHandler::class)));

    expect($monitor->hasRegisteredHandler())->toBeTrue();
});

function monitorWithFailingConfig(RecordingTransport $transport): Monitor
{
    $config = new FailingConfigRepository([
        'monitor' => [
            'enabled' => true,
            'url' => 'https://monitor.test/api/events',
            'token' => 'test-token',
            'environments' => ['testing'],
            'timeout' => 2,
            'ignored_exceptions' => [],
        ],
    ]);

    return new Monitor(
        config: new MonitorConfig($config),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );
}

it('swallows a config that throws while reporting', function (): void {
    $transport = new RecordingTransport;

    $monitor = monitorWithFailingConfig($transport);

    $monitor->report(new RuntimeException('boom'));

    expect($monitor->bufferCount())->toBe(0);

    $monitor->flush();

    expect($transport->payloads)->toBeEmpty();
});

it('swallows a config that throws while reporting a failed job', function (): void {
    $monitor = monitorWithFailingConfig(new RecordingTransport);

    $monitor->reportFailedJob(new RuntimeException('boom'), ['job' => 'App\\Jobs\\Whatever']);

    expect($monitor->occurrenceCount())->toBe(0);
});

it('swallows a config that throws while recording a log event', function (): void {
    $monitor = monitorWithFailingConfig(new RecordingTransport);

    $monitor->recordLog(LogLevel::Error, 'boom', []);

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});
