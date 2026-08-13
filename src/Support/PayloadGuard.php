<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use UnitEnum;
use BackedEnum;
use DateTimeInterface;

final readonly class PayloadGuard
{
    public const string OBJECT = '[OBJECT]';

    public const string TRUNCATED = '[TRUNCATED]';

    private const int MAX_DEPTH = 12;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function guard(array $payload): array
    {
        $guarded = [];

        foreach ($payload as $key => $value) {
            $guarded[$this->key($key)] = $this->value($value, 1);
        }

        return $guarded;
    }

    /**
     * @template TKey of array-key
     *
     * @param  TKey  $key
     * @return (TKey is string ? string : int)
     */
    private function key(string|int $key): string|int
    {
        return is_string($key) ? Utf8::repair($key) : $key;
    }

    private function value(mixed $value, int $depth): mixed
    {
        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return self::TRUNCATED;
            }

            $clean = [];

            foreach ($value as $key => $nested) {
                $clean[$this->key($key)] = $this->value($nested, $depth + 1);
            }

            return $clean;
        }

        if (is_object($value)) {
            return $this->value($this->fromObject($value), $depth);
        }

        if (is_string($value)) {
            return Utf8::repair($value);
        }

        if (is_float($value) && ! is_finite($value)) {
            return match (true) {
                is_nan($value) => 'NAN',
                $value > 0 => 'INF',
                default => '-INF',
            };
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return self::OBJECT;
    }

    private function fromObject(object $value): string
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
}
