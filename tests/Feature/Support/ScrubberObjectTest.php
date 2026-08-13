<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Database\Eloquent\Model;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\Scrubber;

beforeEach(function (): void {
    Http::fake();
});

function scrubbed(mixed $value): mixed
{
    return new Scrubber(['password', 'api_key'])->scrub(['value' => $value])['value'];
}

it('never lets an object leak its properties', function (): void {
    $model = new class
    {
        public array $attributes = ['password' => 'hunter2', 'api_key' => 'k_live_123'];

        public string $token = 'tok_live_123';
    };

    expect(scrubbed($model))->toBe('[OBJECT]');
});

it('replaces a plain object nested in an array', function (): void {
    expect(scrubbed(['deep' => ['deeper' => new stdClass]]))
        ->toBe(['deep' => ['deeper' => '[OBJECT]']]);
});

it('collapses a stringable instead of casting it', function (): void {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'leaky-value';
        }
    };

    expect(scrubbed($stringable))->toBe('[OBJECT]');
});

it('never invokes a throwing stringable', function (): void {
    $broken = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('toString exploded');
        }
    };

    expect(scrubbed($broken))->toBe('[OBJECT]');
});

it('collapses an eloquent model instead of unfolding its attributes', function (): void {
    $model = new class extends Model
    {
        protected $guarded = [];
    };

    $model->forceFill(['email' => 'customer@example.test', 'api_key' => 'k_live_123']);

    expect(scrubbed($model))->toBe('[OBJECT]');
});

it('never ships model attributes through a log context', function (): void {
    $model = new class extends Model
    {
        protected $guarded = [];
    };

    $model->forceFill(['address' => 'Hidden Street 7']);

    Log::error('a model slipped into the context', ['user' => $model]);

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $body = (string) json_encode($request->data());

        expect($request->data()['occurrences'][0]['context']['user'])->toBe('[OBJECT]')
            ->and($body)->not->toContain('Hidden Street 7');

        return true;
    });
});

it('keeps a date as an atom string', function (): void {
    expect(scrubbed(new DateTimeImmutable('2026-08-10T12:00:00+00:00')))
        ->toBe('2026-08-10T12:00:00+00:00');
});

it('keeps enum values', function (): void {
    expect(scrubbed(LogLevel::Error))->toBe('error');
});

it('keeps a pure enum name', function (): void {
    expect(scrubbed(TestPureEnum::First))->toBe('First');
});

it('still redacts a scrubbed key holding an object', function (): void {
    expect(new Scrubber(['password'])->scrub(['password' => new stdClass])['password'])
        ->toBe('[REDACTED]');
});

it('never ships object properties from a log context', function (): void {
    $model = new class
    {
        public string $password = 'hunter2';
    };

    Log::error('a model slipped into the context', ['model' => $model]);

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $body = (string) json_encode($request->data());

        expect($request->data()['occurrences'][0]['context']['model'])->toBe('[OBJECT]')
            ->and($body)->not->toContain('hunter2');

        return true;
    });
});

enum TestPureEnum
{
    case First;
}
