<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use Throwable;

final readonly class StackTraceFormatter
{
    public const int MAX_FRAMES = 30;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function format(Throwable $throwable): array
    {
        $frames = [];

        foreach (array_slice($throwable->getTrace(), 0, self::MAX_FRAMES) as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'],
                'class' => $frame['class'] ?? null,
            ];
        }

        return $frames;
    }
}
