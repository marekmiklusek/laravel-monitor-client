<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

final readonly class OccurrenceTruncator
{
    public const int TRUNCATED_STACK_FRAMES = 10;

    public const int DEFAULT_MESSAGE_LENGTH = 8000;

    public function __construct(
        private PayloadGuard $guard = new PayloadGuard,
    ) {
        // ...
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    public function truncate(array $occurrence, int $maxBytes, int $maxMessageLength = self::DEFAULT_MESSAGE_LENGTH): array
    {
        $capped = $this->capMessages($this->guard->guard($occurrence), $maxMessageLength);

        $truncated = $capped !== $occurrence;
        $occurrence = $capped;

        if ($this->fits($occurrence, $maxBytes)) {
            return $truncated ? ['truncated' => true, ...$occurrence] : $occurrence;
        }

        foreach ([$this->dropBreadcrumbs(...), $this->shortenStack(...), $this->dropContext(...)] as $step) {
            $occurrence = $step($occurrence);

            if ($this->fits($occurrence, $maxBytes)) {
                break;
            }
        }

        return ['truncated' => true, ...$occurrence];
    }

    /**
     * @param  array<string, mixed>  $occurrence
     */
    public function size(array $occurrence): int
    {
        $encoded = json_encode($occurrence);

        return $encoded === false ? PHP_INT_MAX : mb_strlen($encoded, '8bit');
    }

    /**
     * @param  array<string, mixed>  $occurrence
     */
    private function fits(array $occurrence, int $maxBytes): bool
    {
        return $this->size($occurrence) <= $maxBytes;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    private function dropBreadcrumbs(array $occurrence): array
    {
        if (! array_key_exists('breadcrumbs', $occurrence)) {
            return $occurrence;
        }

        unset($occurrence['breadcrumbs']);

        return $occurrence;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    private function shortenStack(array $occurrence): array
    {
        $stack = $occurrence['stack'] ?? null;

        if (! is_array($stack) || count($stack) <= self::TRUNCATED_STACK_FRAMES) {
            return $occurrence;
        }

        $occurrence['stack'] = array_slice($stack, 0, self::TRUNCATED_STACK_FRAMES);

        return $occurrence;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    private function dropContext(array $occurrence): array
    {
        $context = $occurrence['context'] ?? null;

        if (! is_array($context) || $context === []) {
            return $occurrence;
        }

        $occurrence['context'] = [];

        return $occurrence;
    }

    /**
     * @param  array<string, mixed>  $occurrence
     * @return array<string, mixed>
     */
    private function capMessages(array $occurrence, int $maxLength): array
    {
        if (array_key_exists('message', $occurrence)) {
            $occurrence['message'] = $this->cap($occurrence['message'], $maxLength);
        }

        $breadcrumbs = $occurrence['breadcrumbs'] ?? null;

        if (! is_array($breadcrumbs)) {
            return $occurrence;
        }

        $occurrence['breadcrumbs'] = array_map(
            function (mixed $breadcrumb) use ($maxLength): mixed {
                if (! is_array($breadcrumb) || ! array_key_exists('message', $breadcrumb)) {
                    return $breadcrumb;
                }

                $breadcrumb['message'] = $this->cap($breadcrumb['message'], $maxLength);

                return $breadcrumb;
            },
            $breadcrumbs,
        );

        return $occurrence;
    }

    private function cap(mixed $message, int $maxLength): mixed
    {
        if (! is_string($message) || mb_strlen($message) <= $maxLength) {
            return $message;
        }

        $omitted = mb_strlen($message) - $maxLength;

        return mb_substr($message, 0, $maxLength).sprintf('... [truncated, %d chars omitted]', $omitted);
    }
}
