<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\BreadcrumbBuffer;

function recordBreadcrumb(BreadcrumbBuffer $buffer, string $message, LogLevel $level = LogLevel::Info): void
{
    $buffer->record($level, $message, [], new DateTimeImmutable('2026-08-10T12:00:00+00:00'));
}

it('records a breadcrumb with its level, message, context and timestamp', function (): void {
    $buffer = new BreadcrumbBuffer;

    $buffer->record(LogLevel::Warning, 'careful', ['key' => 'value'], new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

    expect($buffer->all())->toBe([[
        'level' => 'warning',
        'message' => 'careful',
        'context' => ['key' => 'value'],
        'logged_at' => '2026-08-10T12:00:00+00:00',
    ]]);
});

it('keeps only the most recent entries', function (): void {
    $buffer = new BreadcrumbBuffer(2);

    recordBreadcrumb($buffer, 'first');
    recordBreadcrumb($buffer, 'second');
    recordBreadcrumb($buffer, 'third');

    expect($buffer->count())->toBe(2)
        ->and(array_column($buffer->all(), 'message'))->toBe(['second', 'third']);
});

it('records nothing with a zero or negative limit', function (int $limit): void {
    $buffer = new BreadcrumbBuffer($limit);

    recordBreadcrumb($buffer, 'dropped');

    expect($buffer->count())->toBe(0)
        ->and($buffer->all())->toBe([]);
})->with([0, -1]);

it('truncates a long message', function (): void {
    $buffer = new BreadcrumbBuffer;

    recordBreadcrumb($buffer, str_repeat('a', 2000));

    expect(mb_strlen((string) $buffer->all()[0]['message']))->toBe(1000);
});

it('empties itself on clear', function (): void {
    $buffer = new BreadcrumbBuffer;

    recordBreadcrumb($buffer, 'gone');
    $buffer->clear();

    expect($buffer->count())->toBe(0)
        ->and($buffer->all())->toBe([]);
});

it('reindexes the entries after dropping the oldest', function (): void {
    $buffer = new BreadcrumbBuffer(1);

    recordBreadcrumb($buffer, 'first');
    recordBreadcrumb($buffer, 'second');

    expect(array_keys($buffer->all()))->toBe([0]);
});
