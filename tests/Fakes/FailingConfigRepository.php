<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use Illuminate\Config\Repository;

final class FailingConfigRepository extends Repository
{
    /**
     * @param  array<int, string>|string  $key
     */
    public function get($key, $default = null): mixed
    {
        if ($key === 'monitor.scrub_keys') {
            throw new RuntimeException('config blew up');
        }

        return parent::get($key, $default);
    }
}
