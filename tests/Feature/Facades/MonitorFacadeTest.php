<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Facades\Monitor;
use MarekMiklusek\MonitorClient\Monitor as MonitorManager;

beforeEach(function (): void {
    Http::fake();
});

it('resolves the monitor instance', function (): void {
    expect(Monitor::getFacadeRoot())->toBe(app(MonitorManager::class));
});

it('reports and flushes through the facade', function (): void {
    Monitor::report(new RuntimeException('through the facade'));

    expect(Monitor::bufferCount())->toBe(1);

    Monitor::flush();

    Http::assertSent(fn ($request): bool => $request->data()['occurrences'][0]['message'] === 'through the facade');
});

it('sends a heartbeat through the facade', function (): void {
    Monitor::heartbeat();

    Http::assertSent(fn ($request): bool => $request->data()['occurrences'][0]['type'] === 'heartbeat');
});

it('is not registered as a global alias', function (): void {
    expect(class_exists('\Monitor', false))->toBeFalse();
});
