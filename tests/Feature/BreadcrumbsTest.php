<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Events\JobFailed;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Contracts\Events\Dispatcher;

beforeEach(function (): void {
    Http::fake();
});

it('attaches the recorded breadcrumbs to an exception', function (): void {
    $monitor = app(Monitor::class);

    Log::info('user opened the checkout', ['order' => 12]);
    Log::debug('payment gateway called');

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'exception');

        expect($occurrence['breadcrumbs'])->toHaveCount(2)
            ->and($occurrence['breadcrumbs'][0]['level'])->toBe('info')
            ->and($occurrence['breadcrumbs'][0]['message'])->toBe('user opened the checkout')
            ->and($occurrence['breadcrumbs'][0]['context'])->toBe(['order' => 12])
            ->and($occurrence['breadcrumbs'][0]['logged_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
            ->and($occurrence['breadcrumbs'][1]['level'])->toBe('debug');

        return true;
    });
});

it('attaches the recorded breadcrumbs to a failed job', function (): void {
    Log::info('job started');

    app(Dispatcher::class)->dispatch(new JobFailed('redis', new FakeJob, new RuntimeException('boom')));

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'failed_job');

        expect($occurrence['breadcrumbs'])->toHaveCount(1)
            ->and($occurrence['breadcrumbs'][0]['message'])->toBe('job started');

        return true;
    });
});

it('records breadcrumbs of every level', function (string $level): void {
    Log::log($level, 'noted');

    expect(app(Monitor::class)->breadcrumbCount())->toBe(1);
})->with(['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency']);

it('throws the buffer away when nothing fails', function (): void {
    Log::info('nothing to see here');

    expect(app(Monitor::class)->breadcrumbCount())->toBe(1);

    app(Monitor::class)->flush();

    Http::assertNothingSent();
});

it('drops the breadcrumbs on a flush that sends nothing', function (): void {
    $monitor = app(Monitor::class);

    Log::info('nothing failed here');

    expect($monitor->breadcrumbCount())->toBe(1);

    $monitor->flush();

    expect($monitor->breadcrumbCount())->toBe(0);

    Http::assertNothingSent();
});

it('never carries breadcrumbs from one queue job into the next', function (): void {
    $events = app(Dispatcher::class);
    $monitor = app(Monitor::class);

    Log::info('trail of the job that succeeded');

    $events->dispatch(new JobProcessed('redis', new FakeJob));

    expect($monitor->breadcrumbCount())->toBe(0);

    $events->dispatch(new JobFailed('redis', new FakeJob, new RuntimeException('a later job failed')));

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'failed_job');

        expect($occurrence)->not->toHaveKey('breadcrumbs');

        return true;
    });
});

it('clears the breadcrumbs after a flush', function (): void {
    $monitor = app(Monitor::class);

    Log::info('before');
    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->breadcrumbCount())->toBe(0);
});

it('keeps only the last N breadcrumbs', function (): void {
    config()->set('monitor.breadcrumbs_limit', 3);

    $monitor = app(Monitor::class);

    foreach (range(1, 5) as $index) {
        Log::info('line '.$index);
    }

    expect($monitor->breadcrumbCount())->toBe(3);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $breadcrumbs = collect($request->data()['occurrences'])->firstWhere('type', 'exception')['breadcrumbs'];

        expect(array_column($breadcrumbs, 'message'))->toBe(['line 3', 'line 4', 'line 5']);

        return true;
    });
});

it('records nothing with a zero limit', function (): void {
    config()->set('monitor.breadcrumbs_limit', 0);

    Log::info('dropped');

    expect(app(Monitor::class)->breadcrumbCount())->toBe(0);
});

it('scrubs the breadcrumb context', function (): void {
    $monitor = app(Monitor::class);

    Log::info('leaky', ['secret' => 'shhh']);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $breadcrumbs = collect($request->data()['occurrences'])->firstWhere('type', 'exception')['breadcrumbs'];

        expect($breadcrumbs[0]['context']['secret'])->toBe('[REDACTED]');

        return true;
    });
});

it('truncates a long breadcrumb message', function (): void {
    $monitor = app(Monitor::class);

    Log::info(str_repeat('a', 2000));

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $breadcrumbs = collect($request->data()['occurrences'])->firstWhere('type', 'exception')['breadcrumbs'];

        expect(mb_strlen((string) $breadcrumbs[0]['message']))->toBe(1000);

        return true;
    });
});

it('collects no breadcrumbs when they are switched off', function (): void {
    config()->set('monitor.collect_breadcrumbs', false);

    $monitor = app(Monitor::class);

    Log::info('dropped');

    expect($monitor->breadcrumbCount())->toBe(0);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(fn ($request): bool => ! array_key_exists(
        'breadcrumbs',
        collect($request->data()['occurrences'])->firstWhere('type', 'exception'),
    ));
});

it('omits the breadcrumbs key when nothing was recorded', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(fn ($request): bool => ! array_key_exists(
        'breadcrumbs',
        collect($request->data()['occurrences'])->firstWhere('type', 'exception'),
    ));
});
