<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Occurrences;

use DateTimeImmutable;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

final readonly class FailedJobOccurrence extends MonitorOccurrence
{
    /**
     * @param  array<int, array<string, mixed>>  $stack
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $breadcrumbs
     */
    public function __construct(
        DateTimeImmutable $occurredAt,
        private string $exceptionClass,
        private string $message,
        private string $file,
        private int $line,
        private array $stack,
        private array $context,
        private array $breadcrumbs = [],
    ) {
        parent::__construct($occurredAt);
    }

    public function type(): OccurrenceType
    {
        return OccurrenceType::FailedJob;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        $payload = [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'stack' => $this->stack,
            'context' => $this->context,
        ];

        if ($this->breadcrumbs !== []) {
            $payload['breadcrumbs'] = $this->breadcrumbs;
        }

        return $payload;
    }
}
