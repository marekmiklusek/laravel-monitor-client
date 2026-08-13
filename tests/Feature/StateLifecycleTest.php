<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Events\JobFailed;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Console\Events\CommandFinished;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

beforeEach(function (): void {
    Http::fake();
});

function finishJob(): void
{
    app(Dispatcher::class)->dispatch(new JobProcessed('redis', new FakeJob));
}

function failJobNow(string $message): void
{
    app(Dispatcher::class)->dispatch(new JobFailed('redis', new FakeJob, new RuntimeException($message)));
}

it('carries no state from one job into the next across many jobs', function (): void {
    $monitor = app(Monitor::class);

    foreach (range(1, 5) as $index) {
        Log::info('trail of job '.$index);
        finishJob();

        expect($monitor->breadcrumbCount())->toBe(0)
            ->and($monitor->occurrenceCount())->toBe(0)
            ->and($monitor->droppedCount())->toBe(0);
    }

    Log::info('trail of the job that fails');
    failJobNow('the sixth job failed');

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'failed_job');

        expect(collect($occurrence['breadcrumbs'] ?? [])->pluck('message')->all())
            ->toBe(['trail of the job that fails']);

        return true;
    });
});

it('leaves no residue after a console command finishes', function (): void {
    $monitor = app(Monitor::class);

    Log::info('command trail');
    $monitor->report(new RuntimeException('command crashed'));

    app(Dispatcher::class)->dispatch(new CommandFinished('some:command', new ArrayInput([]), new NullOutput, 0));

    expect($monitor->bufferCount())->toBe(0)
        ->and($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});

it('leaves no residue after the worker stops', function (): void {
    $monitor = app(Monitor::class);

    Log::info('worker trail');
    Log::error('worker error');

    app(Dispatcher::class)->dispatch(new WorkerStopping);

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0);
});

it('starts every request from a clean slate', function (): void {
    $monitor = app(Monitor::class);

    foreach (range(1, 3) as $index) {
        Log::info('request '.$index.' trail');
        Log::error('request '.$index.' error');

        $monitor->flush();

        expect($monitor->occurrenceCount())->toBe(0)
            ->and($monitor->breadcrumbCount())->toBe(0)
            ->and($monitor->droppedCount())->toBe(0);
    }

    expect(Http::recorded())->toHaveCount(3);
});

it('never reports a dropped count belonging to an earlier flush', function (): void {
    config()->set('monitor.max_buffered_occurrences', 2);

    $monitor = app(Monitor::class);

    foreach (range(1, 6) as $index) {
        Log::error('first wave '.$index);
    }

    $monitor->flush();

    Log::error('second wave');

    $monitor->flush();

    $messages = collect(Http::recorded())
        ->flatMap(fn ($pair): array => $pair[0]->data()['occurrences'])
        ->pluck('message')
        ->all();

    expect(collect($messages)->filter(fn (string $m): bool => str_contains($m, 'dropped')))
        ->toHaveCount(1);
});

it('keeps collecting after a flush that failed to send', function (): void {
    Http::fake(fn () => Http::response('nope', 500));

    $monitor = app(Monitor::class);

    Log::error('before the failure');
    $monitor->flush();

    expect($monitor->occurrenceCount())->toBe(0);

    Log::error('after the failure');

    expect($monitor->occurrenceCount())->toBe(1);

    $monitor->flush();

    expect(Http::recorded())->toHaveCount(2);
});

it('survives a long worker loop without growing', function (): void {
    $monitor = app(Monitor::class);

    foreach (range(1, 50) as $index) {
        Log::debug('noise '.$index);
        Log::info('more noise '.$index);

        if ($index % 5 === 0) {
            failJobNow('job '.$index.' failed');
        } else {
            finishJob();
        }
    }

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($monitor->breadcrumbCount())->toBe(0)
        ->and($monitor->bufferCount())->toBe(0);
});
