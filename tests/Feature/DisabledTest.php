<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;

beforeEach(function (): void {
    Http::fake();
    config()->set('monitor.enabled', false);
});

it('sends nothing when the package is disabled', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertNothingSent();
});

it('does not even buffer when the package is disabled', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));

    expect($monitor->bufferCount())->toBe(0);
});

it('sends no heartbeat when the package is disabled', function (): void {
    app(Monitor::class)->heartbeat();

    Http::assertNothingSent();
});

it('stays inert when no url is configured', function (): void {
    config()->set('monitor.enabled', true);
    config()->set('monitor.url');

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);

    Http::assertNothingSent();
});
