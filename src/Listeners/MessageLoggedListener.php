<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Listeners;

use Illuminate\Log\Events\MessageLogged;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\Silencer;

final readonly class MessageLoggedListener
{
    public function __construct(
        private Monitor $monitor,
        private ?string $channel = null,
        private Silencer $silencer = new Silencer,
    ) {
        // ...
    }

    public function handle(MessageLogged $event): void
    {
        if (Silencer::logging()) {
            return;
        }

        $this->silencer->run(function () use ($event): void {
            $level = LogLevel::tryFromName($event->level);

            if (! $level instanceof LogLevel) {
                return;
            }

            $this->monitor->recordLog(
                level: $level,
                message: $event->message,
                context: $event->context,
                channel: $this->channel,
            );
        });
    }
}
