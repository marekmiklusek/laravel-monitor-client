<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Tests\Fakes\LeakyJobCommand;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Events\JobFailed;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Contracts\Events\Dispatcher;

beforeEach(function (): void {
    Http::fake();
});

function failJob(Throwable $throwable, ?FakeJob $job = null, string $connection = 'redis'): void
{
    app(Dispatcher::class)->dispatch(new JobFailed(
        $connection,
        $job ?? new FakeJob,
        $throwable,
    ));
}

it('reports a failed job as a failed_job occurrence', function (): void {
    failJob(new RuntimeException('job blew up'));

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $occurrence = $request->data()['occurrences'][0];

        expect($occurrence['type'])->toBe('failed_job')
            ->and($occurrence['exception_class'])->toBe(RuntimeException::class)
            ->and($occurrence['message'])->toBe('job blew up')
            ->and($occurrence['file'])->toBeString()
            ->and($occurrence['line'])->toBeInt()
            ->and($occurrence['stack'])->toBeArray()
            ->and($occurrence['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

        return true;
    });
});

it('carries the job class, connection, queue and attempts', function (): void {
    failJob(
        new RuntimeException('boom'),
        new FakeJob(name: 'App\\Jobs\\SendInvoice', attempts: 3, queue: 'invoices'),
        'sqs',
    );

    Http::assertSent(function ($request): bool {
        $context = $request->data()['occurrences'][0]['context'];

        expect($context['job'])->toBe('App\\Jobs\\SendInvoice')
            ->and($context['connection'])->toBe('sqs')
            ->and($context['queue'])->toBe('invoices')
            ->and($context['attempts'])->toBe(3);

        return true;
    });
});

it('scrubs the job payload', function (): void {
    failJob(new RuntimeException('boom'), new FakeJob(payload: [
        'uuid' => 'abc-123',
        'tags' => ['email' => 'customer@example.com', 'password' => 'hunter2'],
    ]));

    Http::assertSent(function ($request): bool {
        $payload = $request->data()['occurrences'][0]['context']['payload'];

        expect($payload['uuid'])->toBe('abc-123')
            ->and($payload['tags']['email'])->toBe('customer@example.com')
            ->and($payload['tags']['password'])->toBe('[REDACTED]');

        return true;
    });
});

it('never ships the serialized job object from a realistic payload', function (): void {
    $command = serialize(new LeakyJobCommand);

    failJob(new RuntimeException('boom'), new FakeJob(payload: [
        'uuid' => 'abc-123',
        'displayName' => 'App\\Jobs\\ChargeOrder',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => 3,
        'data' => [
            'commandName' => 'App\\Jobs\\ChargeOrder',
            'command' => $command,
        ],
    ]));

    Http::assertSent(function ($request): bool {
        $payload = $request->data()['occurrences'][0]['context']['payload'];

        expect($payload['data']['command'])->toBe('[SERIALIZED]')
            ->and($payload['data']['commandName'])->toBe('App\\Jobs\\ChargeOrder')
            ->and((string) json_encode($request->data()))->not->toContain('sk_live_LEAKED');

        return true;
    });
});

it('truncates long values and deep nesting in the job payload', function (): void {
    failJob(new RuntimeException('boom'), new FakeJob(payload: [
        'long' => str_repeat('a', 2000),
        'deep' => ['one' => ['two' => ['three' => 'too far']]],
    ]));

    Http::assertSent(function ($request): bool {
        $payload = $request->data()['occurrences'][0]['context']['payload'];

        expect(mb_strlen((string) $payload['long']))->toBe(1000)
            ->and($payload['deep']['one']['two'])->toBe('[TRUNCATED]');

        return true;
    });
});

it('replaces objects in the job payload with a placeholder', function (): void {
    failJob(new RuntimeException('boom'), new FakeJob(payload: ['object' => new stdClass]));

    Http::assertSent(fn ($request): bool => $request->data()['occurrences'][0]['context']['payload']['object'] === '[OBJECT]');
});

it('collects nothing when failed job collection is switched off', function (): void {
    config()->set('monitor.collect_failed_jobs', false);

    failJob(new RuntimeException('ignored'));

    Http::assertNothingSent();
});

it('collects no failed job when the package is inert', function (): void {
    config()->set('monitor.enabled', false);

    failJob(new RuntimeException('ignored'));

    Http::assertNothingSent();
});

it('keeps the exception buffer separate from the failed job buffer', function (): void {
    $monitor = app(Monitor::class);

    $monitor->reportFailedJob(new RuntimeException('failed job'), ['job' => 'App\\Jobs\\Whatever']);

    expect($monitor->bufferCount())->toBe(0)
        ->and($monitor->occurrenceCount())->toBe(1);
});
