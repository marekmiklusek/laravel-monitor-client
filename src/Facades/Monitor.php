<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Facades;

use Illuminate\Support\Facades\Facade;
use MarekMiklusek\MonitorClient\Monitor as MonitorManager;

/**
 * @method static void handles(\Illuminate\Foundation\Configuration\Exceptions $exceptions)
 * @method static void report(\Throwable $throwable)
 * @method static void reportFailedJob(\Throwable $throwable, array<string, mixed> $job)
 * @method static void recordLog(\MarekMiklusek\MonitorClient\Enums\LogLevel $level, string $message, array<array-key, mixed> $context, ?string $channel = null)
 * @method static void heartbeat()
 * @method static void flush()
 * @method static int bufferCount()
 * @method static int occurrenceCount()
 * @method static int droppedCount()
 * @method static int breadcrumbCount()
 *
 * @see MonitorManager
 */
final class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MonitorManager::class;
    }
}
