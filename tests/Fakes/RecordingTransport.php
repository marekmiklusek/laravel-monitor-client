<?php

declare(strict_types=1);

namespace Tests\Fakes;

use MarekMiklusek\MonitorClient\Contracts\Transport;

final class RecordingTransport implements Transport
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $payloads = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $this->payloads[] = $payload;
    }
}
