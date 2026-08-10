<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use Throwable;

use function app;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use MarekMiklusek\MonitorClient\MonitorConfig;

final class Silencer
{
    private static bool $logging = false;

    public static function logging(): bool
    {
        return self::$logging;
    }

    public function run(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            $this->log($throwable::class, 'Laravel Monitor client failure was silenced.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $key, string $message, array $context = []): void
    {
        if (self::$logging) {
            return;
        }

        self::$logging = true;

        try {
            if ($this->throttled($key)) {
                return;
            }

            Log::channel(app(MonitorConfig::class)->logChannel())
                ->warning($message, $context);
        } catch (Throwable) {
            // ...
        } finally {
            self::$logging = false;
        }
    }

    private function throttled(string $key): bool
    {
        try {
            $seconds = app(MonitorConfig::class)->logThrottleMinutes() * 60;

            return ! Cache::add('monitor:log:'.$key, true, $seconds);
        } catch (Throwable) {
            return false;
        }
    }
}
