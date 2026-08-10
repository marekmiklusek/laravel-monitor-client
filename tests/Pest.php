<?php

declare(strict_types=1);

use Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Log\Events\MessageLogged;

uses(TestCase::class)->in(__DIR__);

/**
 * @return ArrayObject<int, MessageLogged>
 */
function capturedLogs(): ArrayObject
{
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($logs): void {
        $logs->append($event);
    });

    return $logs;
}
