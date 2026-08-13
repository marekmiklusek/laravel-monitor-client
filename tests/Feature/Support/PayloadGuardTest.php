<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\LogLevel;
use MarekMiklusek\MonitorClient\Support\PayloadGuard;

function guarded(mixed $value): mixed
{
    $guard = new PayloadGuard;

    return $guard->guard(['value' => $value])['value'];
}

it('repairs invalid utf8 in values and keys', function (): void {
    $guard = new PayloadGuard;

    $guarded = $guard->guard(["broken\xB1key" => "broken\xB1value"]);

    expect(json_encode($guarded))->not->toBeFalse()
        ->and(mb_check_encoding((string) array_key_first($guarded), 'UTF-8'))->toBeTrue();
});

it('keeps integer keys as integers', function (): void {
    expect(guarded([5 => 'five']))->toBe([5 => 'five']);
});

it('replaces a plain object', function (): void {
    expect(guarded(new stdClass))->toBe('[OBJECT]');
});

it('collapses a stringable instead of casting it', function (): void {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'order #1';
        }
    };

    expect(guarded($stringable))->toBe('[OBJECT]');
});

it('never invokes a throwing stringable', function (): void {
    $broken = new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('exploded');
        }
    };

    expect(guarded($broken))->toBe('[OBJECT]');
});

it('keeps a date as an atom string', function (): void {
    expect(guarded(new DateTimeImmutable('2026-08-10T12:00:00+00:00')))
        ->toBe('2026-08-10T12:00:00+00:00');
});

it('keeps a backed enum value and a pure enum name', function (): void {
    expect(guarded(LogLevel::Error))->toBe('error')
        ->and(guarded(GuardPureEnum::First))->toBe('First');
});

it('leaves string length to the truncator', function (): void {
    expect(mb_strlen((string) guarded(str_repeat('x', 30_000))))->toBe(30_000);
});

it('stops at the depth ceiling', function (): void {
    $deep = 'leaf';

    foreach (range(1, 30) as $ignored) {
        $deep = ['nested' => $deep];
    }

    expect(json_encode(guarded($deep)))->toContain('[TRUNCATED]');
});

it('survives a recursive array', function (): void {
    $recursive = ['name' => 'loop'];
    $recursive['self'] = &$recursive;

    expect(json_encode(guarded($recursive)))->not->toBeFalse();
});

it('renders non finite floats as strings', function (): void {
    expect(guarded(NAN))->toBe('NAN')
        ->and(guarded(INF))->toBe('INF')
        ->and(guarded(-INF))->toBe('-INF');
});

it('keeps ordinary scalars and null untouched', function (): void {
    expect(guarded(null))->toBeNull()
        ->and(guarded(true))->toBeTrue()
        ->and(guarded(42))->toBe(42)
        ->and(guarded(1.5))->toBe(1.5)
        ->and(guarded('plain'))->toBe('plain');
});

it('replaces a resource', function (): void {
    $handle = fopen('php://memory', 'r');

    expect(guarded($handle))->toBe('[OBJECT]');

    fclose($handle);
});

it('guards a whole payload without losing its shape', function (): void {
    $payload = [
        'schema_version' => 1,
        'environment' => 'testing',
        'occurrences' => [['type' => 'log', 'message' => 'entry']],
    ];

    $guard = new PayloadGuard;

    expect($guard->guard($payload))->toBe($payload);
});

enum GuardPureEnum
{
    case First;
}
