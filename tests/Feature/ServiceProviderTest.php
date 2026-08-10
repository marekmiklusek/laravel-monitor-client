<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\NonFoundationHandler;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\MonitorConfig;
use Illuminate\Contracts\Debug\ExceptionHandler;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\MonitorServiceProvider;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Transport\HttpTransport;

it('boots the package service provider', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(MonitorServiceProvider::class);
});

it('binds the transport to the http implementation', function (): void {
    expect(app(Transport::class))->toBeInstanceOf(HttpTransport::class);
});

it('publishes the config file', function (): void {
    $paths = MonitorServiceProvider::pathsToPublish(MonitorServiceProvider::class, 'monitor-config');

    expect($paths)->not->toBeEmpty()
        ->and(array_values($paths)[0])->toEndWith('monitor.php');
});

it('registers the heartbeat command', function (): void {
    expect(array_keys(app(Illuminate\Contracts\Console\Kernel::class)->all()))
        ->toContain('monitor:heartbeat');
});

it('sends nothing from the transport without a url', function (): void {
    Http::fake();

    config()->set('monitor.url');

    new HttpTransport(app(MonitorConfig::class))->send(['occurrences' => []]);

    Http::assertNothingSent();
});

it('resolves a null user id outside a bound container', function (): void {
    $container = new Container;
    Container::setInstance($container);

    try {
        expect($container->bound(Guard::class))->toBeFalse()
            ->and((new ContextResolver)->resolve()['user_id'])->toBeNull();
    } finally {
        Container::setInstance(app());
    }
});

it('skips auto registration when it is switched off', function (): void {
    config()->set('monitor.auto_register', false);

    new MonitorServiceProvider(app())->boot();

    expect(app(Monitor::class)->hasRegisteredHandler())->toBeTrue();
});

it('skips registration when the handler is already attached', function (): void {
    expect(app(Monitor::class)->hasRegisteredHandler())->toBeTrue();

    new MonitorServiceProvider(app())->boot();

    expect(app(Monitor::class)->hasRegisteredHandler())->toBeTrue();
});

it('skips and logs a warning when the handler has no reportable method', function (): void {
    app()->forgetInstance(Monitor::class);
    app()->instance(ExceptionHandler::class, new NonFoundationHandler);

    $logs = capturedLogs();

    new MonitorServiceProvider(app())->boot();

    expect(app(Monitor::class)->hasRegisteredHandler())->toBeFalse()
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]->level)->toBe('warning')
        ->and($logs[0]->message)->toContain('auto-register skipped')
        ->and($logs[0]->message)->toContain(NonFoundationHandler::class);
});

it('stays inert when the package is disabled', function (): void {
    config()->set('monitor.enabled', false);

    app()->forgetInstance(Monitor::class);

    new MonitorServiceProvider(app())->boot();

    expect(app(Monitor::class)->hasRegisteredHandler())->toBeFalse();
});
