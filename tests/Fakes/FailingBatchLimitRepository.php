<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use Illuminate\Config\Repository;

final class FailingBatchLimitRepository extends Repository
{
    /**
     * @param  array<int, string>|string  $key
     */
    public function get($key, $default = null): mixed
    {
        if ($key === 'monitor.max_occurrences_per_request') {
            throw new RuntimeException('batch limit blew up');
        }

        return parent::get($key, $default);
    }
}
