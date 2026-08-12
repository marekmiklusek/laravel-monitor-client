<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Tests\Fakes\RecordingTransport;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;

beforeEach(function (): void {
    Http::fake();
});

it('still sends a batch when a message is not valid utf8', function (): void {
    Log::error("broken \xB1\x31 message");

    app(Monitor::class)->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $occurrence = $request->data()['occurrences'][0];

        expect(mb_check_encoding((string) $occurrence['message'], 'UTF-8'))->toBeTrue()
            ->and($occurrence['message'])->toContain('broken')
            ->and($occurrence['message'])->toContain('message');

        return true;
    });
});

it('still sends a batch when a log context holds invalid utf8', function (): void {
    Log::error('fine message', ['blob' => "\xB1\x31", 'kept' => 'value']);

    app(Monitor::class)->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $context = $request->data()['occurrences'][0]['context'];

        expect(mb_check_encoding((string) $context['blob'], 'UTF-8'))->toBeTrue()
            ->and($context['kept'])->toBe('value');

        return true;
    });
});

it('still sends a batch when invalid utf8 sits in a context key', function (): void {
    Log::error('fine message', ["broken\xB1key" => 'value']);

    app(Monitor::class)->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        foreach (array_keys($request->data()['occurrences'][0]['context']) as $key) {
            expect(mb_check_encoding((string) $key, 'UTF-8'))->toBeTrue();
        }

        return true;
    });
});

it('still sends a batch when a breadcrumb holds invalid utf8', function (): void {
    $monitor = app(Monitor::class);

    Log::info("breadcrumb \xB1\x31");
    $monitor->report(new RuntimeException('crashed'));
    $monitor->flush();

    Http::assertSentCount(1);

    Http::assertSent(function ($request): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'exception');

        expect(mb_check_encoding((string) $occurrence['breadcrumbs'][0]['message'], 'UTF-8'))->toBeTrue();

        return true;
    });
});

it('repairs an environment name that is not valid utf8', function (): void {
    $transport = new RecordingTransport;

    config()->set('monitor.environments', ["prod\xB1uction"]);

    $monitor = new Monitor(
        config: app(MonitorConfig::class),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: "prod\xB1uction",
    );

    $monitor->recordLog(LogLevel::Error, 'entry', []);
    $monitor->flush();

    expect($transport->payloads)->toHaveCount(1)
        ->and(json_encode($transport->payloads[0]))->not->toBeFalse()
        ->and(mb_check_encoding((string) $transport->payloads[0]['environment'], 'UTF-8'))->toBeTrue();
});

it('repairs an environment name on the heartbeat path', function (): void {
    $transport = new RecordingTransport;

    config()->set('monitor.environments', ["prod\xB1uction"]);

    $monitor = new Monitor(
        config: app(MonitorConfig::class),
        transport: $transport,
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver,
        stackTraceFormatter: new StackTraceFormatter,
        environment: "prod\xB1uction",
    );

    $monitor->heartbeat();

    expect($transport->payloads)->toHaveCount(1)
        ->and(json_encode($transport->payloads[0]))->not->toBeFalse();
});

it('leaves valid utf8 untouched', function (): void {
    Log::error('príliš žluťoučký kůň úpěl ďábelské ódy');

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $occurrence = $request->data()['occurrences'][0];

        expect($occurrence['message'])->toBe('príliš žluťoučký kůň úpěl ďábelské ódy')
            ->and($occurrence)->not->toHaveKey('truncated');

        return true;
    });
});
