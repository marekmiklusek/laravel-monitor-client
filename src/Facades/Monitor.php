<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Facades;

use Illuminate\Support\Facades\Facade;
use MarekMiklusek\MonitorClient\Monitor as MonitorManager;

/**
 * @method static void handles(\Illuminate\Foundation\Configuration\Exceptions $exceptions)
 * @method static void report(\Throwable $throwable)
 * @method static void heartbeat()
 * @method static void flush()
 * @method static int bufferCount()
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
