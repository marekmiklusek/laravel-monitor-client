<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;

beforeEach(function (): void {
    Http::fake();
});

it('sends nothing when the current environment is not allowed', function (): void {
    config()->set('monitor.environments', ['production']);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);

    Http::assertNothingSent();
});

it('sends nothing on an empty environment list', function (): void {
    config()->set('monitor.environments', []);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertNothingSent();
});

it('sends when the current environment is allowed', function (): void {
    config()->set('monitor.environments', ['staging', 'testing', 'production']);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSentCount(1);
});
