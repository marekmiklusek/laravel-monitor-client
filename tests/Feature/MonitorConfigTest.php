<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\MonitorConfig;

function monitorConfig(): MonitorConfig
{
    return app(MonitorConfig::class);
}

it('reads the configured values', function (): void {
    expect(monitorConfig()->enabled())->toBeTrue()
        ->and(monitorConfig()->url())->toBe('https://monitor.test/api/events')
        ->and(monitorConfig()->token())->toBe('test-token')
        ->and(monitorConfig()->timeout())->toBe(2)
        ->and(monitorConfig()->autoRegister())->toBeTrue()
        ->and(monitorConfig()->environments())->toBe(['testing']);
});

it('treats an empty url or token as absent', function (): void {
    config()->set('monitor.url', '');
    config()->set('monitor.token', '');

    expect(monitorConfig()->url())->toBeNull()
        ->and(monitorConfig()->token())->toBeNull();
});

it('treats a non string url or token as absent', function (): void {
    config()->set('monitor.url', 123);
    config()->set('monitor.token', ['array']);

    expect(monitorConfig()->url())->toBeNull()
        ->and(monitorConfig()->token())->toBeNull();
});

it('falls back to a sane timeout', function (): void {
    config()->set('monitor.timeout', 'not-a-number');

    expect(monitorConfig()->timeout())->toBe(2);
});

it('never allows a zero or negative timeout', function (): void {
    config()->set('monitor.timeout', 0);

    expect(monitorConfig()->timeout())->toBe(1);

    config()->set('monitor.timeout', -5);

    expect(monitorConfig()->timeout())->toBe(1);
});

it('accepts a numeric string timeout', function (): void {
    config()->set('monitor.timeout', '7');

    expect(monitorConfig()->timeout())->toBe(7);
});

it('ignores malformed list values', function (): void {
    config()->set('monitor.environments', 'production');
    config()->set('monitor.ignored_exceptions', 'nope');
    config()->set('monitor.scrub_keys', 42);

    expect(monitorConfig()->environments())->toBe([])
        ->and(monitorConfig()->ignoredExceptions())->toBe([])
        ->and(monitorConfig()->scrubKeys())->toBe([]);
});

it('drops non string entries from lists', function (): void {
    config()->set('monitor.scrub_keys', ['password', 123, null, 'secret']);

    expect(monitorConfig()->scrubKeys())->toBe(['password', 'secret']);
});

it('collects input by default', function (): void {
    expect(monitorConfig()->collectInput())->toBeTrue();
});

it('can disable input collection', function (): void {
    config()->set('monitor.collect_input', false);

    expect(monitorConfig()->collectInput())->toBeFalse();
});

it('defaults to no dedicated log channel', function (): void {
    expect(monitorConfig()->logChannel())->toBeNull();
});

it('reads the configured log channel', function (): void {
    config()->set('monitor.log_channel', 'monitoring');

    expect(monitorConfig()->logChannel())->toBe('monitoring');
});

it('treats an empty or non string log channel as absent', function (): void {
    config()->set('monitor.log_channel', '');

    expect(monitorConfig()->logChannel())->toBeNull();

    config()->set('monitor.log_channel', 123);

    expect(monitorConfig()->logChannel())->toBeNull();
});

it('defaults the log throttle to five minutes', function (): void {
    expect(monitorConfig()->logThrottleMinutes())->toBe(5);
});

it('reads a numeric log throttle', function (): void {
    config()->set('monitor.log_throttle_minutes', '10');

    expect(monitorConfig()->logThrottleMinutes())->toBe(10);
});

it('never allows a log throttle below one minute', function (): void {
    config()->set('monitor.log_throttle_minutes', 0);

    expect(monitorConfig()->logThrottleMinutes())->toBe(1);
});

it('falls back to a sane log throttle', function (): void {
    config()->set('monitor.log_throttle_minutes', 'not-a-number');

    expect(monitorConfig()->logThrottleMinutes())->toBe(5);
});

it('does not run without a url', function (): void {
    config()->set('monitor.url');

    expect(monitorConfig()->shouldRun('testing'))->toBeFalse();
});

it('does not run when disabled', function (): void {
    config()->set('monitor.enabled', false);

    expect(monitorConfig()->shouldRun('testing'))->toBeFalse();
});

it('does not run in an unlisted environment', function (): void {
    expect(monitorConfig()->shouldRun('production'))->toBeFalse();
});
