<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use MarekMiklusek\MonitorClient\PayloadBuilder;

beforeEach(function (): void {
    Http::fake();
});

it('sends a heartbeat payload from the artisan command', function (): void {
    $this->artisan('monitor:heartbeat')->assertSuccessful();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        expect($body['schema_version'])->toBe(PayloadBuilder::SCHEMA_VERSION)
            ->and($body['environment'])->toBe('testing')
            ->and($body['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/')
            ->and($body['occurrences'])->toHaveCount(1);

        $occurrence = $body['occurrences'][0];

        expect($occurrence['type'])->toBe('heartbeat')
            ->and($occurrence['occurred_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T/');

        return true;
    });
});

it('sends the heartbeat with a bearer token', function (): void {
    $this->artisan('monitor:heartbeat')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('sends no heartbeat in a disallowed environment', function (): void {
    config()->set('monitor.environments', ['production']);

    $this->artisan('monitor:heartbeat')->assertSuccessful();

    Http::assertNothingSent();
});

it('exits successfully even when the service is unreachable', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('down');
    });

    $this->artisan('monitor:heartbeat')->assertSuccessful();
});
