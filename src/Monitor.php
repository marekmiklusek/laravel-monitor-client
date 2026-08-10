<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use Throwable;
use SplObjectStorage;
use DateTimeImmutable;
use MarekMiklusek\MonitorClient\Support\Scrubber;
use MarekMiklusek\MonitorClient\Support\Silencer;
use Illuminate\Foundation\Configuration\Exceptions;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;
use MarekMiklusek\MonitorClient\Occurrences\MonitorOccurrence;
use MarekMiklusek\MonitorClient\Occurrences\ExceptionOccurrence;
use MarekMiklusek\MonitorClient\Occurrences\HeartbeatOccurrence;

final class Monitor
{
    /**
     * @var SplObjectStorage<Throwable, ExceptionOccurrence>
     */
    private SplObjectStorage $buffer;

    private bool $registered = false;

    public function __construct(
        private readonly MonitorConfig $config,
        private readonly Transport $transport,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ContextResolver $contextResolver,
        private readonly StackTraceFormatter $stackTraceFormatter,
        private readonly string $environment,
        private readonly Silencer $silencer = new Silencer,
    ) {
        $this->buffer = new SplObjectStorage;
    }

    public function handles(Exceptions $exceptions): void
    {
        try {
            if ($this->registered) {
                return;
            }

            $this->registered = true;

            $exceptions->reportable(function (Throwable $throwable): void {
                $this->report($throwable);
            });
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to attach to the exception handler.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        }
    }

    public function hasRegisteredHandler(): bool
    {
        return $this->registered;
    }

    public function markHandlerRegistered(): void
    {
        $this->registered = true;
    }

    public function report(Throwable $throwable): void
    {
        try {
            if (Silencer::logging()) {
                return;
            }

            if (! $this->config->shouldRun($this->environment)) {
                return;
            }

            if ($this->isIgnored($throwable)) {
                return;
            }

            if ($this->buffer->contains($throwable)) {
                return;
            }

            $scrubber = new Scrubber($this->config->scrubKeys());

            $this->buffer->attach($throwable, ExceptionOccurrence::fromThrowable(
                throwable: $throwable,
                occurredAt: new DateTimeImmutable,
                stack: $this->stackTraceFormatter->format($throwable),
                context: $scrubber->scrub($this->contextResolver->resolve()),
            ));
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to buffer an exception.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        }
    }

    public function heartbeat(): void
    {
        try {
            if (! $this->config->shouldRun($this->environment)) {
                return;
            }

            $this->dispatch([new HeartbeatOccurrence(new DateTimeImmutable)]);
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to send a heartbeat.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        }
    }

    public function flush(): void
    {
        try {
            if ($this->buffer->count() === 0) {
                return;
            }

            $occurrences = [];

            foreach ($this->buffer as $throwable) {
                $occurrences[] = $this->buffer[$throwable];
            }

            $this->buffer = new SplObjectStorage;

            $this->dispatch($occurrences);
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to flush its buffer.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        }
    }

    public function bufferCount(): int
    {
        return $this->buffer->count();
    }

    /**
     * @param  array<int, MonitorOccurrence>  $occurrences
     */
    private function dispatch(array $occurrences): void
    {
        $this->transport->send(
            $this->payloadBuilder->build($occurrences, $this->environment, new DateTimeImmutable)
        );
    }

    private function isIgnored(Throwable $throwable): bool
    {
        return array_any($this->config->ignoredExceptions(), fn (string $ignored): bool => $throwable instanceof $ignored);
    }
}
