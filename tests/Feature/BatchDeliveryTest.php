<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Monitor;
use Tests\Fakes\ExplodingOnSecondTransport;
use Tests\Fakes\FailingBatchLimitRepository;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;

function monitorSendingThrough(ExplodingOnSecondTransport $transport): Monitor
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

it('keeps sending the remaining batches when one batch fails', function (): void {
    config()->set('monitor.max_occurrences_per_request', 2);

    $transport = new ExplodingOnSecondTransport;
    $monitor = monitorSendingThrough($transport);

    $logs = capturedLogs();

    foreach (range(1, 6) as $index) {
        $monitor->recordLog(LogLevel::Error, 'entry '.$index, []);
    }

    $monitor->flush();

    expect($transport->attempts)->toBe(3)
        ->and($transport->payloads)->toHaveCount(2)
        ->and(collect($logs)->pluck('message'))
        ->toContain('Laravel Monitor client failed to send a batch.');
});

it('delivers the batches either side of a failed one', function (): void {
    config()->set('monitor.max_occurrences_per_request', 2);

    $transport = new ExplodingOnSecondTransport;
    $monitor = monitorSendingThrough($transport);

    foreach (range(1, 6) as $index) {
        $monitor->recordLog(LogLevel::Error, 'entry '.$index, []);
    }

    $monitor->flush();

    $delivered = collect($transport->payloads)
        ->flatMap(fn (array $payload): array => $payload['occurrences'])
        ->pluck('message')
        ->all();

    expect($delivered)->toBe(['entry 1', 'entry 2', 'entry 5', 'entry 6']);
});

it('swallows a config that throws while the batches are being cut', function (): void {
    $transport = new ExplodingOnSecondTransport;

    $config = new FailingBatchLimitRepository([
        'monitor' => [
            'enabled' => true,
            'url' => 'https://monitor.test/api/events',
            'environments' => ['testing'],
            'scrub_keys' => [],
        ],
    ]);

    $monitor = new Monitor(
        config: new MonitorConfig($config),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );

    $logs = capturedLogs();

    $monitor->recordLog(LogLevel::Error, 'entry', []);
    $monitor->flush();

    expect($transport->attempts)->toBe(0)
        ->and($monitor->occurrenceCount())->toBe(0)
        ->and(collect($logs)->pluck('message'))
        ->toContain('Laravel Monitor client failed to flush its buffer.');
});

it('empties the buffer even when every batch fails', function (): void {
    config()->set('monitor.max_occurrences_per_request', 1);

    $transport = new ExplodingOnSecondTransport;
    $monitor = monitorSendingThrough($transport);

    foreach (range(1, 3) as $index) {
        $monitor->recordLog(LogLevel::Error, 'entry '.$index, []);
    }

    $monitor->flush();

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->droppedCount())->toBe(0);
});
