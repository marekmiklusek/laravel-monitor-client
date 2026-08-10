<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

it('matches the wire values agreed with the monitoring service', function (): void {
    expect(OccurrenceType::Exception->value)->toBe('exception')
        ->and(OccurrenceType::Heartbeat->value)->toBe('heartbeat');
});

it('lists every supported occurrence type', function (): void {
    expect(OccurrenceType::cases())->toHaveCount(2);
});
