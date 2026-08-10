<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Contracts\Debug\ExceptionHandler;

final class NonFoundationHandler implements ExceptionHandler
{
    public function report(Throwable $e): void
    {
        // ...
    }

    public function shouldReport(Throwable $e): bool
    {
        return false;
    }

    /**
     * @param  Request  $request
     */
    public function render($request, Throwable $e): mixed
    {
        return null;
    }

    /**
     * @param  \Symfony\Component\Console\Output\OutputInterface  $output
     */
    public function renderForConsole($output, Throwable $e): void
    {
        // ...
    }
}
