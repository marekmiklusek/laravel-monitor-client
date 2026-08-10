<?php

declare(strict_types=1);

namespace Tests\Fakes;

use RuntimeException;
use Illuminate\Foundation\Configuration\Exceptions;

final class FailingExceptions extends Exceptions
{
    /**
     * @return never
     */
    public function reportable(callable $reportUsing): void
    {
        throw new RuntimeException('registration blew up');
    }
}
