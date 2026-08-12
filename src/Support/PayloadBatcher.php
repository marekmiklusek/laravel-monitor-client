<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use MarekMiklusek\MonitorClient\Occurrences\MonitorOccurrence;

final readonly class PayloadBatcher
{
    private const int ENVELOPE_BYTES = 512;

    public function __construct(
        private OccurrenceTruncator $truncator = new OccurrenceTruncator,
    ) {
        // ...
    }

    /**
     * @param  array<int, MonitorOccurrence>  $occurrences
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function batch(array $occurrences, int $maxPerRequest, int $maxBytes, int $maxMessageLength): array
    {
        $budget = max(1, $maxBytes - self::ENVELOPE_BYTES);

        $batches = [];
        $batch = [];
        $size = 0;

        foreach ($occurrences as $occurrence) {
            $serialised = $this->truncator->truncate($occurrence->toArray(), $budget, $maxMessageLength);
            $bytes = $this->truncator->size($serialised);

            if ($batch !== [] && (count($batch) >= $maxPerRequest || $size + $bytes > $budget)) {
                $batches[] = $batch;
                $batch = [];
                $size = 0;
            }

            $batch[] = $serialised;
            $size += $bytes;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }
}
