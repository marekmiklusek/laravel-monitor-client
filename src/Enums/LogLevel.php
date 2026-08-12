<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Enums;

enum LogLevel: string
{
    case Debug = 'debug';

    case Info = 'info';

    case Notice = 'notice';

    case Warning = 'warning';

    case Error = 'error';

    case Critical = 'critical';

    case Alert = 'alert';

    case Emergency = 'emergency';

    public static function tryFromName(mixed $level): ?self
    {
        return is_string($level) ? self::tryFrom(mb_strtolower($level)) : null;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    public function isAtLeast(self $threshold): bool
    {
        return $this->severity() >= $threshold->severity();
    }
}
