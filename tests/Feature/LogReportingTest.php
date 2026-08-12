<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Log\Events\MessageLogged;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Listeners\MessageLoggedListener;

beforeEach(function (): void {
    Http::fake();
});

it('reports an error log as a log occurrence', function (): void {
    Log::error('database is gone', ['host' => 'db-1']);

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])
            ->firstWhere('type', 'log');

        expect($occurrence['level'])->toBe('error')
            ->and($occurrence['message'])->toBe('database is gone')
            ->and($occurrence['channel'])->toBe('null')
            ->and($occurrence['context']['host'])->toBe('db-1')
            ->and($occurrence['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

        return true;
    });
});

it('reports every level at or above the threshold', function (string $level): void {
    Log::log($level, 'loud');

    expect(app(Monitor::class)->occurrenceCount())->toBe(1);
})->with(['error', 'critical', 'alert', 'emergency']);

it('ignores every level below the threshold', function (string $level): void {
    Log::log($level, 'quiet');

    expect(app(Monitor::class)->occurrenceCount())->toBe(0);
})->with(['debug', 'info', 'notice', 'warning']);

it('honours a configurable threshold', function (): void {
    config()->set('monitor.log_level', 'warning');

    Log::warning('now loud enough');

    expect(app(Monitor::class)->occurrenceCount())->toBe(1);
});

it('falls back to error for an unknown threshold', function (): void {
    config()->set('monitor.log_level', 'nonsense');

    Log::warning('still ignored');
    Log::error('still collected');

    expect(app(Monitor::class)->occurrenceCount())->toBe(1);
});

it('ignores a log event with an unknown level', function (): void {
    $monitor = app(Monitor::class);

    new MessageLoggedListener($monitor)->handle(new MessageLogged('made-up', 'nope'));

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});

it('scrubs the log context', function (): void {
    Log::error('leaky', ['password' => 'hunter2', 'nested' => ['api_key' => 'abc']]);

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $context = collect($request->data()['occurrences'])->firstWhere('type', 'log')['context'];

        expect($context['password'])->toBe('[REDACTED]')
            ->and($context['nested']['api_key'])->toBe('[REDACTED]');

        return true;
    });
});

it('collects no log occurrence when log collection is switched off', function (): void {
    config()->set('monitor.collect_logs', false);

    Log::error('ignored');

    expect(app(Monitor::class)->occurrenceCount())->toBe(0);
});

it('collects no log occurrence when the package is inert', function (): void {
    config()->set('monitor.enabled', false);

    Log::error('ignored');

    expect(app(Monitor::class)->occurrenceCount())->toBe(0);
});

it('sends buffered log occurrences alongside exceptions in one request', function (): void {
    $monitor = app(Monitor::class);

    Log::error('first');
    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $types = array_column($request->data()['occurrences'], 'type');

        expect($types)->toContain('exception')
            ->and($types)->toContain('log');

        return true;
    });
});

it('reports an error log exactly once even though it also becomes a breadcrumb', function (): void {
    $monitor = app(Monitor::class);

    Log::error('single');
    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $occurrences = $request->data()['occurrences'];

        $logs = array_filter($occurrences, fn (array $occurrence): bool => $occurrence['type'] === 'log');

        expect($logs)->toHaveCount(1);

        return true;
    });
});
