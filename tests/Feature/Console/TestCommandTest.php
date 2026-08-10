<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

it('confirms an accepted test event', function (): void {
    Http::fake([
        '*' => Http::response('', 204),
    ]);

    $this->artisan('monitor:test')
        ->expectsOutputToContain('204')
        ->assertSuccessful();

    Http::assertSentCount(1);
});

it('sends a heartbeat occurrence with the bearer token', function (): void {
    Http::fake();

    $this->artisan('monitor:test')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->data()['occurrences'][0]['type'] === 'heartbeat');
});

it('sends even when the package is disabled', function (): void {
    config()->set('monitor.enabled', false);

    Http::fake();

    $this->artisan('monitor:test')->assertSuccessful();

    Http::assertSentCount(1);
});

it('confirms live reporting when the package is fully active', function (): void {
    Http::fake();

    $this->artisan('monitor:test')
        ->expectsOutputToContain("Live reporting is active for environment 'testing'.")
        ->assertSuccessful();
});

it('warns that live reporting is off when the package is disabled', function (): void {
    config()->set('monitor.enabled', false);

    Http::fake();

    $this->artisan('monitor:test')
        ->expectsOutputToContain('real exceptions are NOT being reported: monitor.enabled is false')
        ->assertSuccessful();
});

it('warns that live reporting is off in a disallowed environment', function (): void {
    config()->set('monitor.environments', ['production']);

    Http::fake();

    $this->artisan('monitor:test')
        ->expectsOutputToContain("environment 'testing' is not in environments ['production']")
        ->assertSuccessful();
});

it('warns with an empty environments list', function (): void {
    config()->set('monitor.environments', []);

    Http::fake();

    $this->artisan('monitor:test')
        ->expectsOutputToContain('is not in environments []')
        ->assertSuccessful();
});

it('fails loudly when the service rejects the event', function (): void {
    Http::fake([
        '*' => Http::response('invalid token', 401),
    ]);

    $this->artisan('monitor:test')
        ->expectsOutputToContain('401')
        ->expectsOutputToContain('invalid token')
        ->assertFailed();
});

it('fails loudly on a connection error', function (): void {
    Http::fake(function (): void {
        throw new ConnectionException('Could not resolve host');
    });

    $this->artisan('monitor:test')
        ->expectsOutputToContain('Could not resolve host')
        ->assertFailed();
});

it('fails loudly when no url is configured', function (): void {
    config()->set('monitor.url');

    $this->artisan('monitor:test')
        ->expectsOutputToContain('MONITOR_URL')
        ->assertFailed();
});
