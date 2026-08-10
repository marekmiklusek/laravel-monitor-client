<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use MarekMiklusek\MonitorClient\MonitorServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MonitorServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.default', 'null');
        $app['config']->set('monitor.enabled', true);
        $app['config']->set('monitor.url', 'https://monitor.test/api/events');
        $app['config']->set('monitor.token', 'test-token');
        $app['config']->set('monitor.environments', ['testing']);
    }
}
