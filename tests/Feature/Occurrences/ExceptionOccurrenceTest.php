<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\OccurrenceType;
use MarekMiklusek\MonitorClient\Occurrences\ExceptionOccurrence;

it('serialises a throwable into the agreed wire shape', function (): void {
    $throwable = new RuntimeException('boom');

    $occurredAt = new DateTimeImmutable('2026-08-10T12:00:00+00:00');

    $occurrence = ExceptionOccurrence::fromThrowable(
        throwable: $throwable,
        occurredAt: $occurredAt,
        stack: [['file' => 'app.php', 'line' => 1, 'function' => 'boot', 'class' => null]],
        context: ['url' => 'https://example.test', 'method' => 'GET', 'user_id' => null],
    );

    expect($occurrence->type())->toBe(OccurrenceType::Exception)
        ->and($occurrence->toArray())->toBe([
            'type' => 'exception',
            'occurred_at' => '2026-08-10T12:00:00+00:00',
            'exception_class' => RuntimeException::class,
            'message' => 'boom',
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'stack' => [['file' => 'app.php', 'line' => 1, 'function' => 'boot', 'class' => null]],
            'context' => ['url' => 'https://example.test', 'method' => 'GET', 'user_id' => null],
        ]);
});
