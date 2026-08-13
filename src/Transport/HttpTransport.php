<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Transport;

use Throwable;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Support\Silencer;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\Support\PayloadGuard;

final class HttpTransport implements Transport
{
    private const int FAILURE_THRESHOLD = 3;

    private int $failures = 0;

    private ?float $retryAt = null;

    public function __construct(
        private readonly MonitorConfig $config,
        private readonly Silencer $silencer = new Silencer,
        private readonly PayloadGuard $guard = new PayloadGuard,
        private readonly float $cooldownSeconds = 60.0,
    ) {
        // ...
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        try {
            if ($this->circuitOpen()) {
                return;
            }

            $url = $this->config->url();

            if ($url === null) {
                return;
            }

            $request = Http::timeout($this->config->timeout())
                ->acceptJson();

            $token = $this->config->token();

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, $this->guard->guard($payload));

            $this->failures = 0;
            $this->retryAt = null;

            if ($response->failed()) {
                $this->silencer->log('http-'.$response->status(), 'Laravel Monitor client request was rejected.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
            }
        } catch (Throwable $throwable) {
            $this->registerFailure();

            $this->silencer->log($throwable::class, 'Laravel Monitor client request failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }

    private function circuitOpen(): bool
    {
        if ($this->retryAt === null) {
            return false;
        }

        if (microtime(true) < $this->retryAt) {
            return true;
        }

        $this->retryAt = null;

        return false;
    }

    private function registerFailure(): void
    {
        $this->failures++;

        if ($this->failures < self::FAILURE_THRESHOLD) {
            return;
        }

        $this->retryAt = microtime(true) + $this->cooldownSeconds;

        $this->silencer->log('circuit-open', 'Laravel Monitor client paused sending after repeated transport failures.', [
            'failures' => $this->failures,
            'cooldown_seconds' => $this->cooldownSeconds,
        ]);
    }
}
