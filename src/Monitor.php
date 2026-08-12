<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient;

use Throwable;
use SplObjectStorage;
use DateTimeImmutable;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\Scrubber;
use MarekMiklusek\MonitorClient\Support\Silencer;
use Illuminate\Foundation\Configuration\Exceptions;
use MarekMiklusek\MonitorClient\Contracts\Transport;
use MarekMiklusek\MonitorClient\Enums\OccurrenceType;
use MarekMiklusek\MonitorClient\Support\PayloadBatcher;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\BreadcrumbBuffer;
use MarekMiklusek\MonitorClient\Occurrences\LogOccurrence;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;
use MarekMiklusek\MonitorClient\Occurrences\MonitorOccurrence;
use MarekMiklusek\MonitorClient\Occurrences\ExceptionOccurrence;
use MarekMiklusek\MonitorClient\Occurrences\FailedJobOccurrence;
use MarekMiklusek\MonitorClient\Occurrences\HeartbeatOccurrence;

final class Monitor
{
    /**
     * @var SplObjectStorage<Throwable, ExceptionOccurrence>
     */
    private SplObjectStorage $buffer;

    /**
     * @var array<int, MonitorOccurrence>
     */
    private array $occurrences = [];

    private bool $registered = false;

    private bool $collecting = false;

    private int $dropped = 0;

    private ?BreadcrumbBuffer $breadcrumbs = null;

    public function __construct(
        private readonly MonitorConfig $config,
        private readonly Transport $transport,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly ContextResolver $contextResolver,
        private readonly StackTraceFormatter $stackTraceFormatter,
        private readonly string $environment,
        private readonly Silencer $silencer = new Silencer,
        private readonly PayloadBatcher $batcher = new PayloadBatcher,
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
            if ($this->guarded()) {
                return;
            }

            if (! $this->config->shouldRun($this->environment)) {
                return;
            }

            if ($this->isIgnored($throwable)) {
                return;
            }

            if ($this->buffer->offsetExists($throwable)) {
                return;
            }

            if (! $this->makeRoomForException()) {
                $this->dropped++;

                return;
            }

            $this->collecting = true;

            $scrubber = new Scrubber($this->config->scrubKeys());

            $this->buffer->offsetSet($throwable, ExceptionOccurrence::fromThrowable(
                throwable: $throwable,
                occurredAt: new DateTimeImmutable,
                stack: $this->stackTraceFormatter->format($throwable),
                context: $scrubber->scrub($this->contextResolver->resolve()),
                breadcrumbs: $this->takeBreadcrumbs(),
            ));
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to buffer an exception.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        } finally {
            $this->collecting = false;
        }
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public function reportFailedJob(Throwable $throwable, array $job): void
    {
        try {
            if ($this->guarded()) {
                return;
            }

            if (! $this->config->shouldRun($this->environment)) {
                return;
            }

            if (! $this->config->collectFailedJobs()) {
                return;
            }

            if (! $this->makeRoomForException()) {
                $this->dropped++;

                return;
            }

            $this->collecting = true;

            $scrubber = new Scrubber($this->config->scrubKeys());

            $this->occurrences[] = new FailedJobOccurrence(
                occurredAt: new DateTimeImmutable,
                exceptionClass: $throwable::class,
                message: $throwable->getMessage(),
                file: $throwable->getFile(),
                line: $throwable->getLine(),
                stack: $this->stackTraceFormatter->format($throwable),
                context: $scrubber->scrub($job),
                breadcrumbs: $this->takeBreadcrumbs(),
            );
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to buffer a failed job.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        } finally {
            $this->collecting = false;
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function recordLog(LogLevel $level, string $message, array $context, ?string $channel = null): void
    {
        try {
            if ($this->guarded()) {
                return;
            }

            if (! $this->config->shouldRun($this->environment)) {
                return;
            }

            $this->collecting = true;

            $scrubber = new Scrubber($this->config->scrubKeys());

            $this->recordBreadcrumb($scrubber, $level, $message, $context);

            if (! $this->config->collectLogs()) {
                return;
            }

            if (! $level->isAtLeast($this->config->logLevel())) {
                return;
            }

            if ($this->occurrenceCount() >= $this->config->maxBufferedOccurrences()) {
                $this->dropped++;

                return;
            }

            $this->occurrences[] = new LogOccurrence(
                occurredAt: new DateTimeImmutable,
                level: $level,
                message: $message,
                channel: $channel,
                context: $scrubber->scrubContext($context),
            );
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to buffer a log event.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        } finally {
            $this->collecting = false;
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
            if ($this->occurrenceCount() === 0 && $this->dropped === 0) {
                $this->breadcrumbs?->clear();

                return;
            }

            $occurrences = [];

            foreach ($this->buffer as $throwable) {
                $occurrences[] = $this->buffer[$throwable];
            }

            $occurrences = [...$occurrences, ...$this->occurrences];

            $dropped = $this->dropped;

            $this->buffer = new SplObjectStorage;
            $this->occurrences = [];
            $this->dropped = 0;
            $this->breadcrumbs?->clear();

            $this->collecting = true;

            if ($dropped > 0) {
                $occurrences[] = $this->droppedNotice($dropped);
            }

            $batches = $this->batcher->batch(
                $occurrences,
                $this->config->maxOccurrencesPerRequest(),
                $this->config->maxPayloadBytes(),
                $this->config->maxMessageLength(),
            );

            foreach ($batches as $batch) {
                $this->sendBatch($batch);
            }
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to flush its buffer.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        } finally {
            $this->collecting = false;
        }
    }

    public function bufferCount(): int
    {
        return $this->buffer->count();
    }

    public function occurrenceCount(): int
    {
        return $this->buffer->count() + count($this->occurrences);
    }

    public function droppedCount(): int
    {
        return $this->dropped;
    }

    public function breadcrumbCount(): int
    {
        return $this->breadcrumbs?->count() ?? 0;
    }

    private function makeRoomForException(): bool
    {
        if ($this->occurrenceCount() < $this->config->maxBufferedOccurrences()) {
            return true;
        }

        foreach ($this->occurrences as $index => $occurrence) {
            if ($occurrence->type() === OccurrenceType::Log) {
                unset($this->occurrences[$index]);

                $this->occurrences = array_values($this->occurrences);
                $this->dropped++;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function sendBatch(array $batch): void
    {
        try {
            $this->transport->send(
                $this->payloadBuilder->buildFromArrays($batch, $this->environment, new DateTimeImmutable)
            );
        } catch (Throwable $caught) {
            $this->silencer->log($caught::class, 'Laravel Monitor client failed to send a batch.', [
                'exception' => $caught::class,
                'message' => $caught->getMessage(),
            ]);
        }
    }

    private function droppedNotice(int $dropped): LogOccurrence
    {
        return new LogOccurrence(
            occurredAt: new DateTimeImmutable,
            level: LogLevel::Warning,
            message: sprintf('monitor: dropped %d occurrences over buffer limit', $dropped),
            channel: null,
            context: ['dropped' => $dropped, 'limit' => $this->config->maxBufferedOccurrences()],
        );
    }

    /**
     * @param  array<array-key, mixed>  $context
     */
    private function recordBreadcrumb(Scrubber $scrubber, LogLevel $level, string $message, array $context): void
    {
        if (! $this->config->collectBreadcrumbs()) {
            return;
        }

        $this->breadcrumbs ??= new BreadcrumbBuffer($this->config->breadcrumbsLimit());

        $this->breadcrumbs->record($level, $message, $scrubber->scrubContext($context), new DateTimeImmutable);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function takeBreadcrumbs(): array
    {
        return $this->breadcrumbs?->all() ?? [];
    }

    private function guarded(): bool
    {
        return $this->collecting || Silencer::logging();
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
