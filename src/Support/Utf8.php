<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

final readonly class Utf8
{
    public static function repair(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
