<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Occurrences;

use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

final readonly class HeartbeatOccurrence extends MonitorOccurrence
{
    public function type(): OccurrenceType
    {
        return OccurrenceType::Heartbeat;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [];
    }
}
