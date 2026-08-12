<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Illuminate\Queue\Jobs\Job;
use Illuminate\Contracts\Queue\Job as JobContract;

final class FakeJob extends Job implements JobContract
{
    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        private readonly string $name = 'App\\Jobs\\ProcessOrder',
        private readonly array $payload = [],
        private readonly int $attempts = 1,
        string $queue = 'default',
        string $connectionName = 'redis',
    ) {
        $this->queue = $queue;
        $this->connectionName = $connectionName;
    }

    public function resolveName(): string
    {
        return $this->name;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function getJobId(): string
    {
        return 'fake-job-id';
    }

    public function getRawBody(): string
    {
        return '{}';
    }
}
