<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use MarekMiklusek\MonitorClient\Contracts\Transport;

final readonly class FailingTransport implements Transport
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void
    {
        throw new RuntimeException('transport blew up');
    }
}
