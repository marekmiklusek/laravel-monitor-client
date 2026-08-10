<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use Illuminate\Http\Request;

final class BrokenRequest extends Request
{
    public function fullUrl(): never
    {
        throw new RuntimeException('no url available');
    }

    public function method(): never
    {
        throw new RuntimeException('no method available');
    }
}
