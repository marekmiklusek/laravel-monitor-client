<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Support\Silencer;

it('runs the callback', function (): void {
    $ran = false;

    (new Silencer)->run(function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
});

it('logs a warning when the callback throws', function (): void {
    $logs = capturedLogs();

    (new Silencer)->run(function (): void {
        throw new RuntimeException('blew up');
    });

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->level)->toBe('warning')
        ->and($logs[0]->message)->toBe('Laravel Monitor client failure was silenced.')
        ->and($logs[0]->context['exception'])->toBe(RuntimeException::class)
        ->and($logs[0]->context['message'])->toBe('blew up');
});

it('swallows errors as well as exceptions', function (): void {
    (new Silencer)->run(function (): void {
        throw new Error('fatal');
    });
})->throwsNoExceptions();

it('logs the same failure type only once per throttle window', function (): void {
    $logs = capturedLogs();

    $silencer = new Silencer;

    $silencer->log('same-key', 'first');
    $silencer->log('same-key', 'second');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('first');
});

it('logs different failure types independently', function (): void {
    $logs = capturedLogs();

    $silencer = new Silencer;

    $silencer->log('key-one', 'first');
    $silencer->log('key-two', 'second');

    expect($logs)->toHaveCount(2);
});

it('logs without throttling when the cache is unavailable', function (): void {
    config()->set('cache.default', 'broken-store');

    $logs = capturedLogs();

    $silencer = new Silencer;

    $silencer->log('same-key', 'first');
    $silencer->log('same-key', 'second');

    expect($logs)->toHaveCount(2);
});

it('ignores a nested log while one is being written', function (): void {
    $logs = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event;

        (new Silencer)->log('nested-key', 'nested');
    });

    (new Silencer)->log('outer-key', 'outer');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('outer');
});

it('swallows a broken log channel and recovers', function (): void {
    config()->set('monitor.log_channel', 'does-not-exist');

    $silencer = new Silencer;

    $silencer->log('broken-channel-key', 'lost');

    config()->set('monitor.log_channel');

    $logs = capturedLogs();

    $silencer->log('recovered-key', 'recovered');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('recovered');
});

it('swallows a container that cannot resolve the config and recovers', function (): void {
    app()->bind(MonitorConfig::class, function (): never {
        throw new RuntimeException('container broken');
    });

    $silencer = new Silencer;

    $silencer->log('broken-container-key', 'lost');

    app()->forgetInstance(MonitorConfig::class);
    app()->bind(MonitorConfig::class, fn (): MonitorConfig => new MonitorConfig(config()));

    $logs = capturedLogs();

    $silencer->log('recovered-container-key', 'recovered');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('recovered');
});

it('writes to the configured log channel', function (): void {
    config()->set('monitor.log_channel', 'null');

    $logs = capturedLogs();

    (new Silencer)->log('channel-key', 'channelled');

    expect($logs)->toHaveCount(1);
});
