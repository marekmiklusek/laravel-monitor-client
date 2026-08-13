<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use Throwable;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\ServiceProvider;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobExceptionOccurred;
use MarekMiklusek\MonitorClient\Support\Silencer;
use MarekMiklusek\MonitorClient\Console\TestCommand;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Transport\HttpTransport;
use MarekMiklusek\MonitorClient\Console\HeartbeatCommand;
use MarekMiklusek\MonitorClient\Listeners\JobFailedListener;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;
use MarekMiklusek\MonitorClient\Listeners\MessageLoggedListener;

final class MonitorServiceProvider extends ServiceProvider
{
    private static bool $shutdownRegistered = false;

    public function register(): void
    {
        $this->silently(function (): void {
            $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

            $this->app->singleton(MonitorConfig::class, fn (): MonitorConfig => new MonitorConfig(
                $this->app->make(Repository::class)
            ));

            $this->app->singleton(
                Transport::class,
                fn (): Transport => new HttpTransport($this->app->make(MonitorConfig::class))
            );

            $this->app->singleton(Monitor::class, fn (): Monitor => new Monitor(
                config: $this->app->make(MonitorConfig::class),
                transport: $this->app->make(Transport::class),
                payloadBuilder: new PayloadBuilder,
                contextResolver: new ContextResolver($this->app->runningInConsole()),
                stackTraceFormatter: new StackTraceFormatter,
                environment: $this->environment(),
            ));
        });
    }

    public function boot(): void
    {
        $this->silently(function (): void {
            $this->publishes([
                __DIR__.'/../config/monitor.php' => $this->app->configPath('monitor.php'),
            ], 'monitor-config');

            if ($this->app->runningInConsole()) {
                $this->commands([HeartbeatCommand::class, TestCommand::class]);
            }

            $config = $this->app->make(MonitorConfig::class);

            if (! $config->shouldRun($this->environment())) {
                return;
            }

            $this->registerExceptionHandler($config);
            $this->registerCollectors();
            $this->registerFlushHooks();
            $this->registerSchedule();
        });
    }

    private function silently(callable $callback): void
    {
        (new Silencer)->run($callback);
    }

    private function registerExceptionHandler(MonitorConfig $config): void
    {
        if (! $config->autoRegister()) {
            return;
        }

        $monitor = $this->app->make(Monitor::class);

        if ($monitor->hasRegisteredHandler()) {
            return;
        }

        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            (new Silencer)->log($handler::class, sprintf(
                'Laravel Monitor client auto-register skipped: unsupported handler %s.',
                $handler::class,
            ));

            return;
        }

        $monitor->markHandlerRegistered();

        $handler->reportable(function (Throwable $throwable) use ($monitor): void {
            $monitor->report($throwable);
        });
    }

    private function registerCollectors(): void
    {
        $monitor = $this->app->make(Monitor::class);
        $events = $this->app->make(Dispatcher::class);

        $jobs = new JobFailedListener($monitor);

        $events->listen(JobFailed::class, function (JobFailed $event) use ($jobs): void {
            $jobs->handle($event);
        });

        $logs = new MessageLoggedListener($monitor, $this->defaultLogChannel());

        $events->listen(MessageLogged::class, function (MessageLogged $event) use ($logs): void {
            $logs->handle($event);
        });
    }

    private function defaultLogChannel(): ?string
    {
        $channel = $this->app->make(Repository::class)->get('logging.default');

        return is_string($channel) && $channel !== '' ? $channel : null;
    }

    private function registerFlushHooks(): void
    {
        $this->app->terminating(function (): void {
            $this->flush();
        });

        $this->registerShutdownFlush();

        $events = $this->app->make(Dispatcher::class);

        foreach ([
            CommandFinished::class,
            JobExceptionOccurred::class,
            JobProcessed::class,
            JobFailed::class,
            Looping::class,
            WorkerStopping::class,
        ] as $event) {
            $events->listen($event, function (): void {
                $this->flush();
            });
        }
    }

    private function registerShutdownFlush(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        self::$shutdownRegistered = true;

        register_shutdown_function($this->flush(...));
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function (): void {
            $this->silently(function (): void {
                $this->app->make(Schedule::class)
                    ->command(HeartbeatCommand::class)
                    ->everyFiveMinutes()
                    ->evenInMaintenanceMode();
            });
        });
    }

    private function environment(): string
    {
        $environment = $this->app->environment();

        return is_string($environment) ? $environment : '';
    }

    private function flush(): void
    {
        $this->silently(function (): void {
            $this->app->make(Monitor::class)->flush();
        });
    }
}
