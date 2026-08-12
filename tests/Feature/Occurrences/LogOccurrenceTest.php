<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;
use MarekMiklusek\MonitorClient\Occurrences\LogOccurrence;

it('serialises the level, message, channel and context', function (): void {
    $occurrence = new LogOccurrence(
        occurredAt: new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        level: LogLevel::Critical,
        message: 'disk is full',
        channel: 'stack',
        context: ['free' => 0],
    );

    expect($occurrence->type())->toBe(OccurrenceType::Log)
        ->and($occurrence->toArray())->toBe([
            'type' => 'log',
            'occurred_at' => '2026-08-10T12:00:00+00:00',
            'level' => 'critical',
            'message' => 'disk is full',
            'channel' => 'stack',
            'context' => ['free' => 0],
        ]);
});

it('serialises a missing channel as null', function (): void {
    $occurrence = new LogOccurrence(
        occurredAt: new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        level: LogLevel::Error,
        message: 'no channel',
        channel: null,
        context: [],
    );

    expect($occurrence->toArray()['channel'])->toBeNull();
});
