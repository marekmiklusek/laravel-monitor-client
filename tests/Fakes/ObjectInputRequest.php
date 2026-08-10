<?php

declare(strict_types=1);

namespace Tests\Fakes;

use stdClass;
use Illuminate\Http\Request;

final class ObjectInputRequest extends Request
{
    /**
     * @param  array<int, string>|mixed|null  $keys
     * @return array<string, mixed>
     */
    public function all($keys = null): array
    {
        return ['object' => new stdClass];
    }
}
