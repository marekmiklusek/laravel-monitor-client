<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;

beforeEach(function (): void {
    Http::fake();
});

function logManyErrors(int $count): void
{
    foreach (range(1, $count) as $index) {
        Log::error('error '.$index);
    }
}

/**
 * @return array<int, array<int, array<string, mixed>>>
 */
function sentBatches(): array
{
    $batches = [];

    Http::assertSent(function ($request) use (&$batches): bool {
        $batches[] = $request->data()['occurrences'];

        return true;
    });

    return $batches;
}

it('sends 250 log events in three batches', function (): void {
    config()->set('monitor.max_buffered_occurrences', 250);

    logManyErrors(250);

    $monitor = app(Monitor::class);

    expect($monitor->occurrenceCount())->toBe(250);

    $monitor->flush();

    Http::assertSentCount(3);

    expect(array_map(count(...), sentBatches()))->toBe([100, 100, 50]);
});

it('never exceeds the per request limit in a single batch', function (): void {
    config()->set('monitor.max_buffered_occurrences', 250);
    config()->set('monitor.max_occurrences_per_request', 40);

    logManyErrors(250);

    app(Monitor::class)->flush();

    Http::assertSentCount(7);

    expect(array_map(count(...), sentBatches()))->toBe([40, 40, 40, 40, 40, 40, 10]);
});

it('sends everything in one request while it fits', function (): void {
    logManyErrors(10);

    app(Monitor::class)->flush();

    Http::assertSentCount(1);
});

it('stops collecting at the buffer limit and counts what it drops', function (): void {
    config()->set('monitor.max_buffered_occurrences', 5);

    logManyErrors(8);

    $monitor = app(Monitor::class);

    expect($monitor->occurrenceCount())->toBe(5)
        ->and($monitor->droppedCount())->toBe(3);
});

it('reports the dropped count as a warning occurrence in the last batch', function (): void {
    config()->set('monitor.max_buffered_occurrences', 5);
    config()->set('monitor.max_occurrences_per_request', 3);

    logManyErrors(8);

    app(Monitor::class)->flush();

    Http::assertSentCount(2);

    $batches = sentBatches();

    $notice = $batches[1][count($batches[1]) - 1];

    expect(array_map(count(...), $batches))->toBe([3, 3])
        ->and($notice['type'])->toBe('log')
        ->and($notice['level'])->toBe('warning')
        ->and($notice['message'])->toBe('monitor: dropped 3 occurrences over buffer limit')
        ->and($notice['context'])->toBe(['dropped' => 3, 'limit' => 5]);
});

it('resets the dropped count after a flush', function (): void {
    config()->set('monitor.max_buffered_occurrences', 2);

    logManyErrors(5);

    $monitor = app(Monitor::class);

    expect($monitor->droppedCount())->toBe(3);

    $monitor->flush();

    expect($monitor->droppedCount())->toBe(0);
});

it('sends the notice alone when everything else was dropped', function (): void {
    config()->set('monitor.max_buffered_occurrences', 1);

    logManyErrors(3);

    $monitor = app(Monitor::class);
    $monitor->flush();

    Http::assertSentCount(1);

    expect(sentBatches()[0])->toHaveCount(2);
});

it('sends nothing when the buffer is empty and nothing was dropped', function (): void {
    app(Monitor::class)->flush();

    Http::assertNothingSent();
});

it('lets an exception through a full buffer by dropping the oldest log event', function (): void {
    config()->set('monitor.max_buffered_occurrences', 3);

    logManyErrors(3);

    $monitor = app(Monitor::class);

    expect($monitor->occurrenceCount())->toBe(3);

    $monitor->report(new RuntimeException('the request crashed'));

    expect($monitor->bufferCount())->toBe(1)
        ->and($monitor->occurrenceCount())->toBe(3)
        ->and($monitor->droppedCount())->toBe(1);

    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $occurrences = $request->data()['occurrences'];

        $exceptions = array_filter($occurrences, fn (array $occurrence): bool => $occurrence['type'] === 'exception');

        expect($exceptions)->toHaveCount(1)
            ->and(array_column($occurrences, 'message'))->toContain('the request crashed')
            ->and(array_column($occurrences, 'message'))->not->toContain('error 1');

        return true;
    });
});

it('lets a failed job through a full buffer by dropping the oldest log event', function (): void {
    config()->set('monitor.max_buffered_occurrences', 3);

    logManyErrors(3);

    $monitor = app(Monitor::class);

    $monitor->reportFailedJob(new RuntimeException('the job crashed'), []);

    expect($monitor->occurrenceCount())->toBe(3)
        ->and($monitor->droppedCount())->toBe(1);

    $monitor->flush();

    Http::assertSent(function ($request): bool {
        expect(array_column($request->data()['occurrences'], 'type'))->toContain('failed_job');

        return true;
    });
});

it('drops a failed job only when the buffer holds nothing but failures', function (): void {
    config()->set('monitor.max_buffered_occurrences', 2);

    $monitor = app(Monitor::class);

    $monitor->reportFailedJob(new RuntimeException('first'), []);
    $monitor->reportFailedJob(new RuntimeException('second'), []);
    $monitor->reportFailedJob(new RuntimeException('third'), []);

    expect($monitor->occurrenceCount())->toBe(2)
        ->and($monitor->droppedCount())->toBe(1);
});

it('drops an exception only when the buffer holds nothing but failures', function (): void {
    config()->set('monitor.max_buffered_occurrences', 2);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('first'));
    $monitor->report(new RuntimeException('second'));
    $monitor->report(new RuntimeException('third'));

    expect($monitor->bufferCount())->toBe(2)
        ->and($monitor->droppedCount())->toBe(1);
});
