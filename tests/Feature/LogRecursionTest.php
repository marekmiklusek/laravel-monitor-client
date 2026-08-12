<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Log\Events\MessageLogged;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Contracts\Events\Dispatcher;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\Silencer;

it('does not collect the warning a failed send writes to the log', function (): void {
    Http::fake(fn () => Http::response('rejected', 500));

    $monitor = app(Monitor::class);

    $logs = capturedLogs();

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('Laravel Monitor client request was rejected.')
        ->and($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);

    Http::assertSentCount(1);
});

it('does not loop when every send fails', function (): void {
    Http::fake(fn () => Http::response('rejected', 500));

    $monitor = app(Monitor::class);

    foreach (range(1, 5) as $index) {
        $monitor->report(new RuntimeException('boom '.$index));
        $monitor->flush();
    }

    expect($monitor->occurrenceCount())->toBe(0);

    Http::assertSentCount(5);
});

it('does not collect the warning a failed job send writes to the log', function (): void {
    Http::fake(fn () => Http::response('rejected', 500));

    $logs = capturedLogs();

    app(Dispatcher::class)->dispatch(new JobFailed('redis', new FakeJob, new RuntimeException('boom')));

    expect($logs)->toHaveCount(1)
        ->and(app(Monitor::class)->occurrenceCount())->toBe(0);

    Http::assertSentCount(1);
});

it('collects nothing at all while a silenced warning is being written', function (): void {
    Http::fake();

    $monitor = app(Monitor::class);

    (new Silencer)->log('recursion-key', 'a silenced warning');

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});

it('buffers no failed job while a silenced warning is being written', function (): void {
    Http::fake();

    $monitor = app(Monitor::class);

    Event::listen(MessageLogged::class, function () use ($monitor): void {
        $monitor->reportFailedJob(new RuntimeException('from inside logging'), []);
    });

    (new Silencer)->log('nested-failed-job', 'a silenced warning');

    expect($monitor->occurrenceCount())->toBe(0);
});

it('records no log event while a silenced warning is being written', function (): void {
    Http::fake();

    $monitor = app(Monitor::class);

    Event::listen(MessageLogged::class, function () use ($monitor): void {
        $monitor->recordLog(LogLevel::Error, 'from inside logging', []);
    });

    (new Silencer)->log('nested-log', 'a silenced warning');

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});

it('keeps collecting ordinary logs after a silenced warning', function (): void {
    Http::fake();

    (new Silencer)->log('recursion-key', 'a silenced warning');

    Log::error('a real application error');

    expect(app(Monitor::class)->occurrenceCount())->toBe(1);
});
