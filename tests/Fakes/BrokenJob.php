<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Contracts\Queue\Job as JobContract;

final class BrokenJob extends Job implements JobContract
{
    public function __construct()
    {
        $this->queue = 'default';
        $this->connectionName = 'redis';
    }

    public function resolveName(): never
    {
        throw new RuntimeException('the job could not be resolved');
    }

    public function attempts(): int
    {
        return 1;
    }

    public function getJobId(): string
    {
        return 'broken-job-id';
    }

    public function getRawBody(): string
    {
        return '{}';
    }
}
