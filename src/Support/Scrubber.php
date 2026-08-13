<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use UnitEnum;
use BackedEnum;
use DateTimeInterface;

final readonly class Scrubber
{
    public const int MAX_KEYS = 100;

    private const string REDACTED = '[REDACTED]';

    private const string OBJECT = '[OBJECT]';

    private const int MAX_DEPTH = 10;

    /**
     * @var array<int, string>
     */
    private array $needles;

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(array $keys)
    {
        $this->needles = array_values(array_filter(
            array_map(mb_strtolower(...), $keys),
            static fn (string $needle): bool => $needle !== '',
        ));
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $data
     * @return array<TKey, mixed>
     */
    public function scrub(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->matches($key)) {
                $scrubbed[$key] = self::REDACTED;

                continue;
            }

            $scrubbed[$key] = match (true) {
                is_array($value) => $this->scrub($value, $depth + 1),
                is_object($value) => $this->stringify($value),
                default => $value,
            };
        }

        return $scrubbed;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrubContext(array $data): array
    {
        $scrubbed = $this->scrub($data);

        if (count($scrubbed) <= self::MAX_KEYS) {
            return $scrubbed;
        }

        $omitted = count($scrubbed) - (self::MAX_KEYS - 1);

        return [
            ...array_slice($scrubbed, 0, self::MAX_KEYS - 1, true),
            '[truncated]' => sprintf('%d entries omitted', $omitted),
        ];
    }

    private function stringify(object $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return self::OBJECT;
    }

    private function matches(string $key): bool
    {
        $key = mb_strtolower($key);

        return array_any($this->needles, fn (string $needle): bool => str_contains($key, $needle));
    }
}
