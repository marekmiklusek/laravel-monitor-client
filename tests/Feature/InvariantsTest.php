<?php

declare(strict_types=1);

use Tests\Fakes\FakeJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Database\Eloquent\Model;
use MarekMiklusek\MonitorClient\Monitor;
use Illuminate\Contracts\Events\Dispatcher;

const SECRET = 'S3CR3T_NEEDLE';

beforeEach(function (): void {
    Http::fake();
});

/**
 * @return array<int, array<int, Closure>>
 */
function hostileValues(): array
{
    return array_map(
        fn (mixed $value): array => [fn (): mixed => $value],
        rawHostileValues(),
    );
}

/**
 * @return array<int, mixed>
 */
function rawHostileValues(): array
{
    $recursive = ['name' => 'loop'];
    $recursive['self'] = &$recursive;

    return [
        SECRET,
        "invalid \xB1\x31 utf8",
        str_repeat('x', 200_000),
        str_repeat('č', 50_000),
        ['password' => SECRET],
        ['nested' => ['deep' => ['deeper' => ['api_key' => SECRET]]]],
        new class
        {
            public string $password = SECRET;

            public array $attributes = ['api_key' => SECRET];
        },
        new class implements Stringable
        {
            public function __toString(): string
            {
                throw new RuntimeException('exploded');
            }
        },
        new class implements Stringable
        {
            public function __toString(): string
            {
                return SECRET;
            }
        },
        (new class extends Model
        {
            protected $guarded = [];
        })->forceFill(['api_key' => SECRET, 'email' => SECRET]),
        fn (): string => 'closure',
        $recursive,
        NAN,
        INF,
        PHP_INT_MAX,
        -0.0,
        null,
        true,
        [],
        ['' => SECRET],
        ["\xB1broken_key" => SECRET],
        array_fill(0, 500, SECRET),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function capturedPayloads(): array
{
    $payloads = [];

    Http::assertSent(function ($request) use (&$payloads): bool {
        $payloads[] = $request->data();

        return true;
    });

    return $payloads;
}

/**
 * @param  array<int, array<string, mixed>>  $payloads
 */
function assertInvariants(array $payloads): void
{
    foreach ($payloads as $payload) {
        $encoded = json_encode($payload);

        expect($encoded)->not->toBeFalse('payload must always serialise')
            ->and((string) $encoded)->not->toContain(SECRET, 'no secret may travel in clear')
            ->and($payload['occurrences'])->toHaveCount(
                min(count($payload['occurrences']), 100),
            );

        expect(mb_strlen((string) $encoded, '8bit'))
            ->toBeLessThanOrEqual(512 * 1024, 'payload must fit the central request cap');

        foreach ($payload['occurrences'] as $occurrence) {
            if (isset($occurrence['message'])) {
                expect(mb_strlen((string) $occurrence['message']))->toBeLessThan(10_000);
            }

            foreach ($occurrence['breadcrumbs'] ?? [] as $breadcrumb) {
                expect(mb_strlen((string) $breadcrumb['message']))->toBeLessThan(10_000);
            }

            if (isset($occurrence['context']) && is_array($occurrence['context'])) {
                expect(count($occurrence['context']))->toBeLessThanOrEqual(100);
            }
        }
    }
}

it('holds every invariant for a hostile log context', function (Closure $case): void {
    $value = $case();

    Log::error('hostile log', ['password' => SECRET, 'payload' => $value]);

    app(Monitor::class)->flush();

    assertInvariants(capturedPayloads());
})->with(hostileValues());

it('holds every invariant for a hostile breadcrumb', function (Closure $case): void {
    $value = $case();

    $monitor = app(Monitor::class);

    Log::info('hostile breadcrumb', ['api_key' => SECRET, 'payload' => $value]);

    $monitor->report(new RuntimeException('crashed'));
    $monitor->flush();

    assertInvariants(capturedPayloads());
})->with(hostileValues());

it('holds every invariant for a hostile failed job payload', function (Closure $case): void {
    $value = $case();

    app(Dispatcher::class)->dispatch(new JobFailed(
        'redis',
        new FakeJob(payload: ['secret' => SECRET, 'payload' => $value]),
        new RuntimeException('job crashed'),
    ));

    assertInvariants(capturedPayloads());
})->with(hostileValues());

it('holds every invariant for a hostile request input', function (): void {
    Illuminate\Support\Facades\Route::post('/hostile', function (): never {
        throw new RuntimeException('crashed');
    });

    $recursive = ['name' => 'loop'];
    $recursive['self'] = &$recursive;

    test()->post('/hostile', [
        'password' => SECRET,
        'broken' => "invalid \xB1\x31 utf8",
        'long' => str_repeat('x', 20_000),
        'multibyte' => str_repeat('č', 5_000),
        'nested' => ['deep' => ['deeper' => ['api_key' => SECRET]]],
        'wide' => array_fill(0, 300, 'filler-value'),
        'empty' => [],
        'null' => null,
        'bool' => true,
        'big_int' => PHP_INT_MAX,
    ]);

    app(Monitor::class)->flush();

    $payloads = capturedPayloads();

    expect($payloads)->not->toBeEmpty();

    foreach ($payloads as $payload) {
        $encoded = (string) json_encode($payload);

        expect(json_encode($payload))->not->toBeFalse()
            ->and($encoded)->not->toContain(SECRET)
            ->and(mb_strlen($encoded, '8bit'))->toBeLessThanOrEqual(512 * 1024);
    }

    assertInvariants($payloads);
});

it('holds every invariant for hostile artisan arguments', function (): void {
    app('request')->server->set('argv', [
        'artisan',
        'tinker',
        '--execute='.str_repeat('x', 300).SECRET,
        '--password='.SECRET,
        SECRET.str_repeat('y', 300),
    ]);

    $monitor = app(Monitor::class);

    $monitor->report(new RuntimeException('crashed in console'));
    $monitor->flush();

    $payloads = capturedPayloads();

    expect($payloads)->not->toBeEmpty();

    assertInvariants($payloads);
});

it('holds every invariant for a hostile query string', function (): void {
    Illuminate\Support\Facades\Route::get('/hostile-query', function (): never {
        throw new RuntimeException('crashed');
    });

    test()->get('/hostile-query?password='.SECRET.'&api_key='.SECRET.'&safe=ok', [
        'Referer' => 'https://app.test/reset?token='.SECRET,
    ]);

    app(Monitor::class)->flush();

    foreach (capturedPayloads() as $payload) {
        expect((string) json_encode($payload))->not->toContain(SECRET);
    }
});

it('holds every invariant under a flood of oversized occurrences', function (): void {
    config()->set('monitor.max_buffered_occurrences', 60);

    foreach (range(1, 200) as $index) {
        Log::error('flood '.$index, [
            'password' => SECRET,
            'blob' => str_repeat('x', 9000),
            'broken' => "\xB1\x31",
        ]);
    }

    app(Monitor::class)->flush();

    $payloads = capturedPayloads();

    expect($payloads)->not->toBeEmpty();

    assertInvariants($payloads);
});

it('holds every invariant for a hostile environment name', function (): void {
    config()->set('monitor.environments', ["prod\xB1uction"]);

    $monitor = new Monitor(
        config: app(MarekMiklusek\MonitorClient\MonitorConfig::class),
        transport: app(MarekMiklusek\MonitorClient\Contracts\Transport::class),
        payloadBuilder: new MarekMiklusek\MonitorClient\PayloadBuilder,
        contextResolver: new MarekMiklusek\MonitorClient\Support\ContextResolver,
        stackTraceFormatter: new MarekMiklusek\MonitorClient\Support\StackTraceFormatter,
        environment: "prod\xB1uction",
    );

    $monitor->recordLog(
        MarekMiklusek\MonitorClient\Enums\LogLevel::Error,
        'entry',
        ['password' => SECRET, 'broken' => "\xB1\x31"],
    );

    $monitor->flush();

    assertInvariants(capturedPayloads());
});
