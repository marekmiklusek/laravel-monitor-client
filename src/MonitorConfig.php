<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use Illuminate\Contracts\Config\Repository;

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
