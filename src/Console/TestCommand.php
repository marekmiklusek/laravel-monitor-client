<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Console;

use Throwable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Support\PayloadGuard;
use MarekMiklusek\MonitorClient\Occurrences\HeartbeatOccurrence;

#[Signature('monitor:test')]
#[Description('Send a test occurrence to the monitoring service and print the result')]
final class TestCommand extends Command
{
    public function handle(MonitorConfig $config, PayloadBuilder $payloadBuilder): int
    {
        $url = $config->url();

        if ($url === null) {
            $this->error('No monitoring url is configured. Set MONITOR_URL and publish the config first.');

            return self::FAILURE;
        }

        $guard = new PayloadGuard;

        $payload = $guard->guard($payloadBuilder->build(
            [new HeartbeatOccurrence(new DateTimeImmutable)],
            $this->environment(),
            new DateTimeImmutable,
        ));

        try {
            $request = Http::timeout($config->timeout())
                ->acceptJson();

            $token = $config->token();

            if ($token !== null) {
                $request = $request->withToken($token);
            }

            $response = $request->post($url, $payload);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Connection to %s failed: %s', $url, $throwable->getMessage()));

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error(sprintf('The monitoring service rejected the test occurrence with HTTP %d.', $response->status()));
            $this->line(mb_substr($response->body(), 0, 500));

            return self::FAILURE;
        }

        $this->info(sprintf('The monitoring service accepted the test occurrence with HTTP %d.', $response->status()));

        $this->reportLiveStatus($config);
        $this->reportHeartbeatStatus($config);

        return self::SUCCESS;
    }

    private function reportHeartbeatStatus(MonitorConfig $config): void
    {
        if (! $this->heartbeatIsScheduled()) {
            $this->warn('Heartbeats are NOT scheduled, so the monitoring service will report this project as down.');

            $this->line($config->shouldRun($this->environment())
                ? '  The schedule could not be inspected.'
                : '  Most likely cause: monitor.enabled is false or monitor.environments does not list this environment.');

            return;
        }

        $this->info('Heartbeats are scheduled every 5 minutes.');
        $this->line('  This only works while cron runs: * * * * * php artisan schedule:run');
    }

    private function heartbeatIsScheduled(): bool
    {
        try {
            foreach ($this->laravel->make(Schedule::class)->events() as $event) {
                if (str_contains((string) $event->command, 'monitor:heartbeat')) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function reportLiveStatus(MonitorConfig $config): void
    {
        if (! $config->enabled()) {
            $this->warn('Connectivity OK, but real exceptions are NOT being reported: monitor.enabled is false.');

            return;
        }

        $environment = $this->environment();

        if (! $config->shouldRun($environment)) {
            $this->warn(sprintf(
                "Connectivity OK, but real exceptions are NOT being reported: environment '%s' is not in environments %s.",
                $environment,
                $this->formatList($config->environments()),
            ));

            return;
        }

        $this->info(sprintf("Live reporting is active for environment '%s'.", $environment));
    }

    /**
     * @param  array<int, string>  $items
     */
    private function formatList(array $items): string
    {
        return $items === [] ? '[]' : sprintf("['%s']", implode("', '", $items));
    }

    private function environment(): string
    {
        $environment = $this->laravel->environment();

        return is_string($environment) ? $environment : '';
    }
}
