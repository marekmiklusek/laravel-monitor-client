<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use Illuminate\Contracts\Config\Repository;
use MarekMiklusek\MonitorClient\Enums\LogLevel;

final readonly class MonitorConfig
{
    public function __construct(
        private Repository $config,
    ) {
        // ...
    }

    public function enabled(): bool
    {
        return (bool) $this->config->get('monitor.enabled', false);
    }

    public function autoRegister(): bool
    {
        return (bool) $this->config->get('monitor.auto_register', true);
    }

    public function url(): ?string
    {
        $url = $this->config->get('monitor.url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function token(): ?string
    {
        $token = $this->config->get('monitor.token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function timeout(): int
    {
        $timeout = $this->config->get('monitor.timeout', 2);

        return is_numeric($timeout) ? max(1, (int) $timeout) : 2;
    }

    public function collectInput(): bool
    {
        return (bool) $this->config->get('monitor.collect_input', true);
    }

    public function collectFailedJobs(): bool
    {
        return (bool) $this->config->get('monitor.collect_failed_jobs', true);
    }

    public function collectLogs(): bool
    {
        return (bool) $this->config->get('monitor.collect_logs', true);
    }

    public function logLevel(): LogLevel
    {
        return LogLevel::tryFromName($this->config->get('monitor.log_level', 'error')) ?? LogLevel::Error;
    }

    /**
     * @return int<1, max>
     */
    public function maxOccurrencesPerRequest(): int
    {
        $max = $this->config->get('monitor.max_occurrences_per_request', 100);

        return is_numeric($max) ? max(1, (int) $max) : 100;
    }

    /**
     * @return int<1, max>
     */
    public function maxPayloadBytes(): int
    {
        $kilobytes = $this->config->get('monitor.max_payload_kilobytes', 400);

        return (is_numeric($kilobytes) ? max(1, (int) $kilobytes) : 400) * 1024;
    }

    /**
     * @return int<1, max>
     */
    public function maxMessageLength(): int
    {
        $length = $this->config->get('monitor.max_message_length', 8000);

        return is_numeric($length) ? max(1, (int) $length) : 8000;
    }

    public function maxBufferedOccurrences(): int
    {
        $max = $this->config->get('monitor.max_buffered_occurrences', 200);

        return is_numeric($max) ? max(1, (int) $max) : 200;
    }

    public function collectBreadcrumbs(): bool
    {
        return (bool) $this->config->get('monitor.collect_breadcrumbs', true);
    }

    public function breadcrumbsLimit(): int
    {
        $limit = $this->config->get('monitor.breadcrumbs_limit', 30);

        return is_numeric($limit) ? max(0, (int) $limit) : 30;
    }

    public function logChannel(): ?string
    {
        $channel = $this->config->get('monitor.log_channel');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    public function logThrottleMinutes(): int
    {
        $minutes = $this->config->get('monitor.log_throttle_minutes', 5);

        return is_numeric($minutes) ? max(1, (int) $minutes) : 5;
    }

    /**
     * @return array<int, string>
     */
    public function environments(): array
    {
        return $this->stringList($this->config->get('monitor.environments', ['production']));
    }

    /**
     * @return array<int, string>
     */
    public function ignoredExceptions(): array
    {
        return $this->stringList($this->config->get('monitor.ignored_exceptions', []));
    }

    /**
     * @return array<int, string>
     */
    public function scrubKeys(): array
    {
        return $this->stringList($this->config->get('monitor.scrub_keys', []));
    }

    public function shouldRun(string $environment): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if ($this->url() === null) {
            return false;
        }

        return in_array($environment, $this->environments(), true);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
