<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Tests\Fakes\BrokenJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Events\JobFailed;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Listeners\JobFailedListener;

beforeEach(function (): void {
    Http::fake();
});

function handleJobFailed(JobFailed $event): Monitor
{
    $monitor = app(Monitor::class);

    new JobFailedListener($monitor)->handle($event);

    return $monitor;
}

it('stops sanitising the job payload once the key budget runs out', function (): void {
    $payload = [];

    foreach (range(1, 150) as $index) {
        $payload['key-'.$index] = $index;
    }

    handleJobFailed(new JobFailed('redis', new FakeJob(payload: $payload), new RuntimeException('boom')))->flush();

    Http::assertSent(function ($request): bool {
        expect($request->data()['occurrences'][0]['context']['payload'])->toHaveCount(100);

        return true;
    });
});

it('swallows a job that throws while its context is being built', function (): void {
    $logs = capturedLogs();

    $monitor = handleJobFailed(new JobFailed('redis', new BrokenJob, new RuntimeException('boom')));

    expect($monitor->occurrenceCount())->toBe(0)
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]->message)->toBe('Laravel Monitor client failure was silenced.');

    Http::assertNothingSent();
});
