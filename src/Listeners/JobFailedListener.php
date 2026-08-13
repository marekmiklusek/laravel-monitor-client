<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Support\Silencer;

final readonly class JobFailedListener
{
    private const string SERIALIZED = '[SERIALIZED]';

    private const int MAX_DEPTH = 3;

    private const int MAX_KEYS = 100;

    private const int MAX_VALUE_LENGTH = 1000;

    public function __construct(
        private Monitor $monitor,
        private Silencer $silencer = new Silencer,
    ) {
        // ...
    }

    public function handle(JobFailed $event): void
    {
        $this->silencer->run(function () use ($event): void {
            $this->monitor->reportFailedJob($event->exception, $this->context($event));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function context(JobFailed $event): array
    {
        return [
            'job' => $event->job->resolveName(),
            'connection' => $event->connectionName,
            'queue' => $event->job->getQueue(),
            'attempts' => $event->job->attempts(),
            'payload' => $this->payload($event->job),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function payload(Job $job): array
    {
        $payload = $job->payload();

        if (isset($payload['data']) && is_array($payload['data']) && array_key_exists('command', $payload['data'])) {
            $payload['data']['command'] = self::SERIALIZED;
        }

        $budget = self::MAX_KEYS;

        return $this->sanitize($payload, 1, $budget);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data, int $depth, int &$budget): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($budget <= 0) {
                break;
            }

            $budget--;

            $clean[$key] = $this->sanitizeValue($value, $depth, $budget);
        }

        return $clean;
    }

    private function sanitizeValue(mixed $value, int $depth, int &$budget): mixed
    {
        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH
                ? '[TRUNCATED]'
                : $this->sanitize($value, $depth + 1, $budget);
        }

        if (is_string($value)) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return '[OBJECT]';
    }
}
