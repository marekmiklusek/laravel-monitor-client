<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    Http::fake();
});

it('skips exceptions listed in the config', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new NotFoundHttpException);
    $monitor->report(new AuthenticationException);
    $monitor->report(new AuthorizationException);

    expect($monitor->bufferCount())->toBe(0);

    $monitor->flush();

    Http::assertNothingSent();
});

it('skips subclasses of ignored exceptions', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new class extends NotFoundHttpException {});

    expect($monitor->bufferCount())->toBe(0);
});

it('still reports exceptions that are not ignored', function (): void {
    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));

    expect($monitor->bufferCount())->toBe(1);
});

it('ignores a custom exception once it is configured', function (): void {
    config()->set('monitor.ignored_exceptions', [RuntimeException::class]);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('boom'));
    $monitor->report(new LogicException('kept'));

    expect($monitor->bufferCount())->toBe(1);
});
