<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Support\OccurrenceTruncator;

beforeEach(function (): void {
    Http::fake();
});

/**
 * @return array<int, array<int, array<string, mixed>>>
 */
function sentPayloadBatches(): array
{
    $batches = [];

    Http::assertSent(function ($request) use (&$batches): bool {
        $batches[] = $request->data()['occurrences'];

        return true;
    });

    return $batches;
}

function logFatEntries(int $count, int $bytes = 4000): void
{
    foreach (range(1, $count) as $index) {
        Log::error('fat '.$index, ['blob' => str_repeat('x', $bytes)]);
    }
}

it('splits a batch by size well below the occurrence count limit', function (): void {
    config()->set('monitor.max_payload_kilobytes', 20);

    logFatEntries(10);

    $monitor = app(Monitor::class);

    expect($monitor->occurrenceCount())->toBe(10);

    $monitor->flush();

    $batches = sentPayloadBatches();

    expect(count($batches))->toBeGreaterThan(1)
        ->and(array_sum(array_map(count(...), $batches)))->toBe(10);

    foreach ($batches as $batch) {
        expect(count($batch))->toBeLessThan(100)
            ->and(mb_strlen((string) json_encode($batch), '8bit'))->toBeLessThanOrEqual(20 * 1024);
    }
});

it('still honours the occurrence count limit when everything is small', function (): void {
    config()->set('monitor.max_occurrences_per_request', 4);

    foreach (range(1, 10) as $index) {
        Log::error('small '.$index);
    }

    app(Monitor::class)->flush();

    expect(array_map(count(...), sentPayloadBatches()))->toBe([4, 4, 2]);
});

it('applies whichever limit is reached first', function (): void {
    config()->set('monitor.max_occurrences_per_request', 3);
    config()->set('monitor.max_payload_kilobytes', 10);

    logFatEntries(6);

    app(Monitor::class)->flush();

    foreach (sentPayloadBatches() as $batch) {
        expect(count($batch))->toBeLessThanOrEqual(3)
            ->and(mb_strlen((string) json_encode($batch), '8bit'))->toBeLessThanOrEqual(10 * 1024);
    }
});

it('truncates an oversized occurrence instead of dropping it', function (): void {
    config()->set('monitor.max_payload_kilobytes', 2);

    Log::error('a single enormous record', ['blob' => str_repeat('x', 20_000)]);

    app(Monitor::class)->flush();

    Http::assertSentCount(1);

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence['truncated'])->toBeTrue()
        ->and($occurrence['type'])->toBe('log')
        ->and($occurrence['message'])->toBe('a single enormous record')
        ->and($occurrence['context'])->toBe([]);
});

it('drops breadcrumbs before it touches the stack or the context', function (): void {
    config()->set('monitor.max_payload_kilobytes', 4);

    foreach (range(1, 20) as $index) {
        Log::info('breadcrumb '.$index, ['blob' => str_repeat('b', 500)]);
    }

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('crashed'));
    $monitor->flush();

    $occurrence = collect(sentPayloadBatches()[0])->firstWhere('type', 'exception');

    expect($occurrence['truncated'])->toBeTrue()
        ->and($occurrence)->not->toHaveKey('breadcrumbs')
        ->and($occurrence['context'])->not->toBe([]);
});

it('shortens a fifty thousand character message below the central limit', function (): void {
    Log::error(str_repeat('m', 50_000));

    app(Monitor::class)->flush();

    Http::assertSentCount(1);

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence['truncated'])->toBeTrue()
        ->and($occurrence['message'])->toStartWith('mmm')
        ->and($occurrence['message'])->toEndWith(sprintf('... [truncated, %d chars omitted]', 50_000 - 8000))
        ->and(mb_strlen((string) $occurrence['message']))->toBeLessThan(10_000);
});

it('shortens a one megabyte message so the occurrence fits', function (): void {
    Log::error(str_repeat('m', 1024 * 1024));

    app(Monitor::class)->flush();

    $batch = sentPayloadBatches()[0];

    expect($batch[0]['truncated'])->toBeTrue()
        ->and(mb_strlen((string) $batch[0]['message']))->toBeLessThan(10_000)
        ->and(mb_strlen((string) json_encode($batch), '8bit'))->toBeLessThanOrEqual(400 * 1024);
});

it('caps a giant log message already at intake', function (): void {
    Log::error(str_repeat('m', 100_000));

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence['message'])->toEndWith(sprintf('... [truncated, %d chars omitted]', 64_000 - 8000));
});

it('does not flag an occurrence as truncated when values are only normalised', function (): void {
    Log::error('entry', ['count' => INF]);

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence)->not->toHaveKey('truncated')
        ->and($occurrence['context']['count'])->toBe('INF');
});

it('cuts the message even when the payload would otherwise fit', function (): void {
    Log::error(str_repeat('m', 9000));

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence['truncated'])->toBeTrue()
        ->and(mb_strlen((string) $occurrence['message']))->toBe(8000 + mb_strlen('... [truncated, 1000 chars omitted]'));
});

it('honours a configured message length', function (): void {
    config()->set('monitor.max_message_length', 100);

    Log::error(str_repeat('m', 500));

    app(Monitor::class)->flush();

    expect((string) sentPayloadBatches()[0][0]['message'])
        ->toBe(str_repeat('m', 100).'... [truncated, 400 chars omitted]');
});

it('caps breadcrumb messages against the same limit', function (): void {
    config()->set('monitor.max_message_length', 50);

    $monitor = app(Monitor::class);

    Log::info(str_repeat('b', 400));
    $monitor->report(new RuntimeException('crashed'));
    $monitor->flush();

    $occurrence = collect(sentPayloadBatches()[0])->firstWhere('type', 'exception');

    expect($occurrence['truncated'])->toBeTrue()
        ->and((string) $occurrence['breadcrumbs'][0]['message'])
        ->toBe(str_repeat('b', 50).'... [truncated, 350 chars omitted]');
});

it('leaves a message under the limit alone', function (): void {
    Log::error(str_repeat('m', 7999));

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence)->not->toHaveKey('truncated')
        ->and(mb_strlen((string) $occurrence['message']))->toBe(7999);
});

it('leaves a message under the limit alone while truncating the rest', function (): void {
    config()->set('monitor.max_payload_kilobytes', 2);

    Log::error('a short message', ['blob' => str_repeat('x', 20_000)]);

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence['truncated'])->toBeTrue()
        ->and($occurrence['message'])->toBe('a short message')
        ->and($occurrence['context'])->toBe([]);
});

it('leaves an occurrence that already fits untouched', function (): void {
    Log::error('small enough', ['key' => 'value']);

    app(Monitor::class)->flush();

    $occurrence = sentPayloadBatches()[0][0];

    expect($occurrence)->not->toHaveKey('truncated')
        ->and($occurrence['context'])->toBe(['key' => 'value']);
});

it('caps a log context at a hundred entries', function (): void {
    $context = [];

    foreach (range(1, 250) as $index) {
        $context['key-'.$index] = $index;
    }

    Log::error('wide context', $context);

    app(Monitor::class)->flush();

    $sent = sentPayloadBatches()[0][0]['context'];

    expect($sent)->toHaveCount(100)
        ->and($sent['[truncated]'])->toBe('151 entries omitted')
        ->and($sent['key-1'])->toBe(1);
});

it('caps a breadcrumb context at a hundred entries', function (): void {
    $context = [];

    foreach (range(1, 250) as $index) {
        $context['key-'.$index] = $index;
    }

    $monitor = app(Monitor::class);

    Log::info('wide breadcrumb', $context);
    $monitor->report(new RuntimeException('crashed'));
    $monitor->flush();

    $occurrence = collect(sentPayloadBatches()[0])->firstWhere('type', 'exception');

    expect($occurrence['breadcrumbs'][0]['context'])->toHaveCount(100);
});

it('leaves a context under the entry limit alone', function (): void {
    Log::error('narrow context', ['one' => 1, 'two' => 2]);

    app(Monitor::class)->flush();

    expect(sentPayloadBatches()[0][0]['context'])->toBe(['one' => 1, 'two' => 2]);
});

it('leaves an occurrence with nothing left to drop alone', function (): void {
    $occurrence = [
        'type' => 'heartbeat',
        'occurred_at' => '2026-08-10T12:00:00+00:00',
        'context' => [],
    ];

    $truncated = (new OccurrenceTruncator)->truncate($occurrence, 10);

    expect($truncated['truncated'])->toBeTrue()
        ->and($truncated['context'])->toBe([]);
});

it('leaves a breadcrumb without a message untouched', function (): void {
    $occurrence = [
        'type' => 'exception',
        'message' => str_repeat('m', 50),
        'breadcrumbs' => [['level' => 'info'], 'not an array'],
    ];

    $truncated = (new OccurrenceTruncator)->truncate($occurrence, 1_000_000, 10);

    expect($truncated['breadcrumbs'])->toBe([['level' => 'info'], 'not an array'])
        ->and($truncated['message'])->toBe(str_repeat('m', 10).'... [truncated, 40 chars omitted]');
});

it('shortens the stack to ten frames when dropping breadcrumbs is not enough', function (): void {
    $occurrence = [
        'type' => 'exception',
        'stack' => array_fill(0, 30, ['file' => str_repeat('f', 200), 'line' => 1]),
        'context' => ['kept' => 'yes'],
    ];

    $truncated = (new OccurrenceTruncator)->truncate($occurrence, 3000);

    expect($truncated['truncated'])->toBeTrue()
        ->and($truncated['stack'])->toHaveCount(OccurrenceTruncator::TRUNCATED_STACK_FRAMES)
        ->and($truncated['context'])->toBe(['kept' => 'yes']);
});
