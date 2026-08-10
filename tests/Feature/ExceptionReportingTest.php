<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\PayloadBuilder;

beforeEach(function (): void {
    Http::fake();
});

it('sends every buffered exception in a single request', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('first'));
    $monitor->report(new LogicException('second'));
    $monitor->report(new RuntimeException('third'));

    Http::assertNothingSent();

    $monitor->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $occurrences = $request->data()['occurrences'];

        expect($occurrences)->toHaveCount(3)
            ->and(array_column($occurrences, 'message'))->toBe(['first', 'second', 'third']);

        return true;
    });
});

it('builds a payload matching the agreed schema', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        expect($body)->toHaveKeys(['schema_version', 'sent_at', 'environment', 'occurrences'])
            ->and($body['schema_version'])->toBe(PayloadBuilder::SCHEMA_VERSION)
            ->and($body['environment'])->toBe('testing')
            ->and($body['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

        $occurrence = $body['occurrences'][0];

        expect($occurrence)->toHaveKeys([
            'type', 'occurred_at', 'exception_class', 'message', 'file', 'line', 'stack', 'context',
        ])
            ->and($occurrence['type'])->toBe('exception')
            ->and($occurrence['exception_class'])->toBe(RuntimeException::class)
            ->and($occurrence['message'])->toBe('boom')
            ->and($occurrence['line'])->toBeInt()
            ->and($occurrence['context'])->toHaveKeys(['url', 'method', 'user_id']);

        return true;
    });
});

it('sends a bearer token', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('reports the same exception instance only once', function (): void {
    $monitor = app(Monitor::class);

    $throwable = new RuntimeException('duplicate');

    $monitor->report($throwable);
    $monitor->report($throwable);
    $monitor->report($throwable);

    expect($monitor->bufferCount())->toBe(1);

    $monitor->flush();

    Http::assertSent(fn ($request): bool => count($request->data()['occurrences']) === 1);
});

it('sends nothing when the buffer is empty', function (): void {
    app(Monitor::class)->flush();

    Http::assertNothingSent();
});

it('empties the buffer after flushing', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    expect($monitor->bufferCount())->toBe(0);

    $monitor->flush();

    Http::assertSentCount(1);
});
