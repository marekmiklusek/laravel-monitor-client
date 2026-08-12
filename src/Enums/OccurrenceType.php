<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Enums;

enum OccurrenceType: string
{
    case Exception = 'exception';

    case Heartbeat = 'heartbeat';

    case FailedJob = 'failed_job';

    case Log = 'log';
}
