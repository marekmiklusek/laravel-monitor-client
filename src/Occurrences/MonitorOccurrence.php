<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Occurrences;

use DateTimeImmutable;
use DateTimeInterface;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

abstract readonly class MonitorOccurrence
{
    public function __construct(
        public DateTimeImmutable $occurredAt,
    ) {
        // ...
    }

    abstract public function type(): OccurrenceType;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;

    /**
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return [
            'type' => $this->type()->value,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            ...$this->payload(),
        ];
    }
}
