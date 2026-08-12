<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use DateTimeImmutable;
use DateTimeInterface;
use MarekMiklusek\MonitorClient\Support\Utf8;
use MarekMiklusek\MonitorClient\Occurrences\MonitorOccurrence;

final readonly class PayloadBuilder
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<int, MonitorOccurrence>  $occurrences
     * @return array<string, mixed>
     */
    public function build(array $occurrences, string $environment, DateTimeImmutable $sentAt): array
    {
        return $this->buildFromArrays(
            array_map(
                static fn (MonitorOccurrence $occurrence): array => $occurrence->toArray(),
                array_values($occurrences),
            ),
            $environment,
            $sentAt,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $occurrences
     * @return array<string, mixed>
     */
    public function buildFromArrays(array $occurrences, string $environment, DateTimeImmutable $sentAt): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'sent_at' => $sentAt->format(DateTimeInterface::ATOM),
            'environment' => Utf8::repair($environment),
            'occurrences' => array_values($occurrences),
        ];
    }
}
