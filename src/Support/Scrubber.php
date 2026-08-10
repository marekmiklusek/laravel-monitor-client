<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

final readonly class Scrubber
{
    private const string REDACTED = '[REDACTED]';

    private const int MAX_DEPTH = 10;

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(
        private array $keys,
    ) {
        // ...
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

            $scrubbed[$key] = is_array($value)
                ? $this->scrub($value, $depth + 1)
                : $value;
        }

        return $scrubbed;
    }

    private function matches(string $key): bool
    {
        return array_any($this->keys, fn (string $needle): bool => mb_strtolower($key) === mb_strtolower($needle));
    }
}
