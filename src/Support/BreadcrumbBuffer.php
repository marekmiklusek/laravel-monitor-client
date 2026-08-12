<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use DateTimeImmutable;
use DateTimeInterface;
use MarekMiklusek\MonitorClient\Enums\LogLevel;

final class BreadcrumbBuffer
{
    private const int MAX_MESSAGE_LENGTH = 1000;

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $breadcrumbs = [];

    public function __construct(
        private readonly int $limit = 30,
    ) {
        // ...
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function record(LogLevel $level, string $message, array $context, DateTimeImmutable $loggedAt): void
    {
        if ($this->limit <= 0) {
            return;
        }

        $this->breadcrumbs[] = [
            'level' => $level->value,
            'message' => mb_substr($message, 0, self::MAX_MESSAGE_LENGTH),
            'context' => $context,
            'logged_at' => $loggedAt->format(DateTimeInterface::ATOM),
        ];

        if (count($this->breadcrumbs) > $this->limit) {
            array_shift($this->breadcrumbs);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return array_values($this->breadcrumbs);
    }

    public function count(): int
    {
        return count($this->breadcrumbs);
    }

    public function clear(): void
    {
        $this->breadcrumbs = [];
    }
}
