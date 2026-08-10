<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Console;

use Illuminate\Console\Command;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Attributes\Description;

#[Signature('monitor:heartbeat')]
#[Description('Send a heartbeat occurrence to the monitoring service')]
final class HeartbeatCommand extends Command
{
    public function handle(Monitor $monitor): int
    {
        $monitor->heartbeat();

        return self::SUCCESS;
    }
}
