<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\OccurrenceType;
use MarekMiklusek\MonitorClient\Occurrences\FailedJobOccurrence;

function failedJobOccurrence(array $breadcrumbs = []): FailedJobOccurrence
{
    return new FailedJobOccurrence(
        occurredAt: new DateTimeImmutable('2026-08-10T12:00:00+00:00'),
        exceptionClass: RuntimeException::class,
        message: 'job blew up',
        file: '/app/Jobs/ProcessOrder.php',
        line: 21,
        stack: [],
        context: ['job' => 'App\\Jobs\\ProcessOrder'],
        breadcrumbs: $breadcrumbs,
    );
}

it('serialises the exception, the job context and the type', function (): void {
    expect(failedJobOccurrence()->type())->toBe(OccurrenceType::FailedJob)
        ->and(failedJobOccurrence()->toArray())->toBe([
            'type' => 'failed_job',
            'occurred_at' => '2026-08-10T12:00:00+00:00',
            'exception_class' => RuntimeException::class,
            'message' => 'job blew up',
            'file' => '/app/Jobs/ProcessOrder.php',
            'line' => 21,
            'stack' => [],
            'context' => ['job' => 'App\\Jobs\\ProcessOrder'],
        ]);
});

it('appends the breadcrumbs when there are any', function (): void {
    $breadcrumbs = [['level' => 'info', 'message' => 'started', 'context' => [], 'logged_at' => '2026-08-10T11:59:00+00:00']];

    expect(failedJobOccurrence($breadcrumbs)->toArray()['breadcrumbs'])->toBe($breadcrumbs);
});
