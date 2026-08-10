<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;

/**
 * Build an exception whose trace is guaranteed to be deeper than the cap.
 */
function throwDeep(int $depth): never
{
    if ($depth <= 0) {
        throw new RuntimeException('deep');
    }

    throwDeep($depth - 1);
}

it('truncates the stack trace to the frame limit', function (): void {
    Http::fake();

    try {
        throwDeep(60);
    } catch (RuntimeException $throwable) {
        $deep = $throwable;
    }

    expect(count($deep->getTrace()))->toBeGreaterThan(StackTraceFormatter::MAX_FRAMES);

    $monitor = app(Monitor::class);
    $monitor->report($deep);
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        expect($request->data()['occurrences'][0]['stack'])
            ->toHaveCount(StackTraceFormatter::MAX_FRAMES);

        return true;
    });
});

it('keeps a short stack trace intact', function (): void {
    $formatter = new StackTraceFormatter;

    $frames = $formatter->format(new RuntimeException('shallow'));

    expect(count($frames))->toBeLessThanOrEqual(StackTraceFormatter::MAX_FRAMES);
});

it('describes each frame with file, line, function and class keys', function (): void {
    $formatter = new StackTraceFormatter;

    try {
        throwDeep(3);
    } catch (RuntimeException $throwable) {
        $frames = $formatter->format($throwable);
    }

    expect($frames)->not->toBeEmpty()
        ->and($frames[0])->toHaveKeys(['file', 'line', 'function', 'class']);
});

it('never leaks trace arguments', function (): void {
    $formatter = new StackTraceFormatter;

    try {
        throwDeep(3);
    } catch (RuntimeException $throwable) {
        $frames = $formatter->format($throwable);
    }

    foreach ($frames as $frame) {
        expect($frame)->not->toHaveKey('args');
    }
});
