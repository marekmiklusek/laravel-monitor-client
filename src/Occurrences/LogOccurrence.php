<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Occurrences;

use DateTimeImmutable;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

final readonly class LogOccurrence extends MonitorOccurrence
{
    /**
     * @param  array<array-key, mixed>  $context
     */
    public function __construct(
        DateTimeImmutable $occurredAt,
        private LogLevel $level,
        private string $message,
        private ?string $channel,
        private array $context,
    ) {
        parent::__construct($occurredAt);
    }

    public function type(): OccurrenceType
    {
        return OccurrenceType::Log;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'level' => $this->level->value,
            'message' => $this->message,
            'channel' => $this->channel,
            'context' => $this->context,
        ];
    }
}
