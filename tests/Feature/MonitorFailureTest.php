<?php

declare(strict_types=1);

use Tests\Fakes\FailingExceptions;
use Tests\Fakes\RecordingTransport;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use Tests\Fakes\FailingConfigRepository;
use MarekMiklusek\MonitorClient\MonitorConfig;
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

it('swallows a config that throws while reporting', function (): void {
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

    $transport = new RecordingTransport;

    $monitor = new Monitor(
        config: new MonitorConfig($config),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );

    $monitor->report(new RuntimeException('boom'));

    expect($monitor->bufferCount())->toBe(0);

    $monitor->flush();

    expect($transport->payloads)->toBeEmpty();
});
