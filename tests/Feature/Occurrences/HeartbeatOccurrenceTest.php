<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\OccurrenceType;
use MarekMiklusek\MonitorClient\Occurrences\HeartbeatOccurrence;

it('serialises to nothing but a type and a timestamp', function (): void {
    $occurrence = new HeartbeatOccurrence(new DateTimeImmutable('2026-08-10T12:00:00+00:00'));

    expect($occurrence->type())->toBe(OccurrenceType::Heartbeat)
        ->and($occurrence->toArray())->toBe([
            'type' => 'heartbeat',
            'occurred_at' => '2026-08-10T12:00:00+00:00',
        ]);
});
