<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Transport;

use Throwable;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Support\Silencer;
use MarekMiklusek\MonitorClient\Contracts\Transport;

final readonly class HttpTransport implements Transport
{
    public function __construct(
        private MonitorConfig $config,
        private Silencer $silencer = new Silencer,
    ) {
        // ...
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        try {
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

            $response = $request->post($url, $payload);

            if ($response->failed()) {
                $this->silencer->log('http-'.$response->status(), 'Laravel Monitor client request was rejected.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);
            }
        } catch (Throwable $throwable) {
            $this->silencer->log($throwable::class, 'Laravel Monitor client request failed.', [
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}
