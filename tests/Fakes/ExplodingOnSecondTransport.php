<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use MarekMiklusek\MonitorClient\Contracts\Transport;

final class ExplodingOnSecondTransport implements Transport
{
    public int $attempts = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $payloads = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        $this->attempts++;

        if ($this->attempts === 2) {
            throw new RuntimeException('the second batch blew up');
        }

        $this->payloads[] = $payload;
    }
}
