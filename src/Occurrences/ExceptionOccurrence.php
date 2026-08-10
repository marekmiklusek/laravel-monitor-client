<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Occurrences;

use Throwable;
use DateTimeImmutable;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;

final readonly class ExceptionOccurrence extends MonitorOccurrence
{
    /**
     * @param  array<int, array<string, mixed>>  $stack
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        DateTimeImmutable $occurredAt,
        private string $exceptionClass,
        private string $message,
        private string $file,
        private int $line,
        private array $stack,
        private array $context,
    ) {
        parent::__construct($occurredAt);
    }

    /**
     * @param  array<int, array<string, mixed>>  $stack
     * @param  array<string, mixed>  $context
     */
    public static function fromThrowable(
        Throwable $throwable,
        DateTimeImmutable $occurredAt,
        array $stack,
        array $context,
    ): self {
        return new self(
            occurredAt: $occurredAt,
            exceptionClass: $throwable::class,
            message: $throwable->getMessage(),
            file: $throwable->getFile(),
            line: $throwable->getLine(),
            stack: $stack,
            context: $context,
        );
    }

    public function type(): OccurrenceType
    {
        return OccurrenceType::Exception;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(): array
    {
        return [
            'exception_class' => $this->exceptionClass,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'stack' => $this->stack,
            'context' => $this->context,
        ];
    }
}
