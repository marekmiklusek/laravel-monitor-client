<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Exceptions\Handler as FoundationHandler;

beforeEach(function (): void {
    Http::fake();
});

it('registers itself with the framework exception handler', function (): void {
    expect(app(Monitor::class)->hasRegisteredHandler())->toBeTrue();
});

it('delivers a handler-reported exception without touching the monitor directly', function (): void {
    app(ExceptionHandler::class)->report(new RuntimeException('end to end'));

    app()->terminate();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $occurrence = $request->data()['occurrences'][0];

        expect($occurrence['type'])->toBe('exception')
            ->and($occurrence['exception_class'])->toBe(RuntimeException::class)
            ->and($occurrence['message'])->toBe('end to end');

        return true;
    });
});

it('reports exceptions passed through the framework handler', function (): void {
    $monitor = app(Monitor::class);

    app(ExceptionHandler::class)->report(new RuntimeException('through the handler'));

    expect($monitor->bufferCount())->toBe(1);

    $monitor->flush();

    Http::assertSent(fn ($request): bool => $request->data()['occurrences'][0]['message'] === 'through the handler');
});

it('does not register twice when handles() is called afterwards', function (): void {
    $monitor = app(Monitor::class);

    $handler = app(ExceptionHandler::class);

    expect($handler)->toBeInstanceOf(FoundationHandler::class);

    $monitor->handles(new Exceptions($handler));

    $handler->report(new RuntimeException('once only'));

    expect($monitor->bufferCount())->toBe(1);
});

it('reports an exception only once even if the handler sees it twice', function (): void {
    $monitor = app(Monitor::class);

    $throwable = new RuntimeException('same instance');

    $handler = app(ExceptionHandler::class);
    $handler->report($throwable);
    $handler->report($throwable);

    expect($monitor->bufferCount())->toBe(1);
});
