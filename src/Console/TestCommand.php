<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Console;

use Throwable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Occurrences\HeartbeatOccurrence;

#[Signature('monitor:test')]
#[Description('Send a test event to the monitoring service and print the result')]
final class TestCommand extends Command
{
    public function handle(MonitorConfig $config, PayloadBuilder $payloadBuilder): int
    {
        $url = $config->url();

        if ($url === null) {
            $this->error('No monitoring url is configured. Set MONITOR_URL and publish the config first.');

            return self::FAILURE;
        }

        $payload = $payloadBuilder->build(
            [new HeartbeatOccurrence(new DateTimeImmutable)],
            $this->environment(),
            new DateTimeImmutable,
        );

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
            $this->error(sprintf('The monitoring service rejected the test event with HTTP %d.', $response->status()));
            $this->line(mb_substr($response->body(), 0, 500));

            return self::FAILURE;
        }

        $this->info(sprintf('The monitoring service accepted the test event with HTTP %d.', $response->status()));

        return self::SUCCESS;
    }

    private function environment(): string
    {
        $environment = $this->laravel->environment();

        return is_string($environment) ? $environment : '';
    }
}
