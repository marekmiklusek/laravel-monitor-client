<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Enums\LogLevel;

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

it('collects failed jobs, logs and breadcrumbs by default', function (): void {
    expect(monitorConfig()->collectFailedJobs())->toBeTrue()
        ->and(monitorConfig()->collectLogs())->toBeTrue()
        ->and(monitorConfig()->collectBreadcrumbs())->toBeTrue()
        ->and(monitorConfig()->logLevel())->toBe(LogLevel::Error)
        ->and(monitorConfig()->breadcrumbsLimit())->toBe(30);
});

it('can disable failed job, log and breadcrumb collection', function (): void {
    config()->set('monitor.collect_failed_jobs', false);
    config()->set('monitor.collect_logs', false);
    config()->set('monitor.collect_breadcrumbs', false);

    expect(monitorConfig()->collectFailedJobs())->toBeFalse()
        ->and(monitorConfig()->collectLogs())->toBeFalse()
        ->and(monitorConfig()->collectBreadcrumbs())->toBeFalse();
});

it('reads a configured log level case insensitively', function (): void {
    config()->set('monitor.log_level', 'WARNING');

    expect(monitorConfig()->logLevel())->toBe(LogLevel::Warning);
});

it('falls back to error for an unknown or non string log level', function (mixed $level): void {
    config()->set('monitor.log_level', $level);

    expect(monitorConfig()->logLevel())->toBe(LogLevel::Error);
})->with(['nonsense', 42, null]);

it('reads a numeric breadcrumbs limit', function (): void {
    config()->set('monitor.breadcrumbs_limit', '10');

    expect(monitorConfig()->breadcrumbsLimit())->toBe(10);
});

it('never allows a negative breadcrumbs limit', function (): void {
    config()->set('monitor.breadcrumbs_limit', -5);

    expect(monitorConfig()->breadcrumbsLimit())->toBe(0);
});

it('falls back to a sane breadcrumbs limit', function (): void {
    config()->set('monitor.breadcrumbs_limit', 'not-a-number');

    expect(monitorConfig()->breadcrumbsLimit())->toBe(30);
});

it('defaults the buffer limits to the central caps', function (): void {
    expect(monitorConfig()->maxOccurrencesPerRequest())->toBe(100)
        ->and(monitorConfig()->maxBufferedOccurrences())->toBe(200)
        ->and(monitorConfig()->maxPayloadBytes())->toBe(400 * 1024)
        ->and(monitorConfig()->maxMessageLength())->toBe(8000);
});

it('reads a configured message length', function (): void {
    config()->set('monitor.max_message_length', '500');

    expect(monitorConfig()->maxMessageLength())->toBe(500);
});

it('never allows a message length below one', function (): void {
    config()->set('monitor.max_message_length', 0);

    expect(monitorConfig()->maxMessageLength())->toBe(1);
});

it('falls back to a sane message length', function (): void {
    config()->set('monitor.max_message_length', 'not-a-number');

    expect(monitorConfig()->maxMessageLength())->toBe(8000);
});

it('reads the payload limit in kilobytes', function (): void {
    config()->set('monitor.max_payload_kilobytes', '64');

    expect(monitorConfig()->maxPayloadBytes())->toBe(64 * 1024);
});

it('never allows a payload limit below one kilobyte', function (): void {
    config()->set('monitor.max_payload_kilobytes', 0);

    expect(monitorConfig()->maxPayloadBytes())->toBe(1024);
});

it('falls back to a sane payload limit', function (): void {
    config()->set('monitor.max_payload_kilobytes', 'not-a-number');

    expect(monitorConfig()->maxPayloadBytes())->toBe(400 * 1024);
});

it('reads numeric buffer limits', function (): void {
    config()->set('monitor.max_occurrences_per_request', '25');
    config()->set('monitor.max_buffered_occurrences', '50');

    expect(monitorConfig()->maxOccurrencesPerRequest())->toBe(25)
        ->and(monitorConfig()->maxBufferedOccurrences())->toBe(50);
});

it('never allows a buffer limit below one', function (): void {
    config()->set('monitor.max_occurrences_per_request', 0);
    config()->set('monitor.max_buffered_occurrences', -5);

    expect(monitorConfig()->maxOccurrencesPerRequest())->toBe(1)
        ->and(monitorConfig()->maxBufferedOccurrences())->toBe(1);
});

it('falls back to sane buffer limits', function (): void {
    config()->set('monitor.max_occurrences_per_request', 'not-a-number');
    config()->set('monitor.max_buffered_occurrences', 'not-a-number');

    expect(monitorConfig()->maxOccurrencesPerRequest())->toBe(100)
        ->and(monitorConfig()->maxBufferedOccurrences())->toBe(200);
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
