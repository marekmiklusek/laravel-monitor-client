<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Contracts;

interface Transport
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): void;
}
