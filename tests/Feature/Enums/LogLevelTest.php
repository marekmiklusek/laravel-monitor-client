<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Enums\LogLevel;

it('lists every psr level', function (): void {
    expect(LogLevel::cases())->toHaveCount(8);
});

it('resolves a level from a case insensitive name', function (): void {
    expect(LogLevel::tryFromName('ERROR'))->toBe(LogLevel::Error)
        ->and(LogLevel::tryFromName('critical'))->toBe(LogLevel::Critical);
});

it('resolves nothing from an unknown or non string name', function (): void {
    expect(LogLevel::tryFromName('made-up'))->toBeNull()
        ->and(LogLevel::tryFromName(null))->toBeNull()
        ->and(LogLevel::tryFromName(42))->toBeNull();
});

it('orders the levels by severity', function (): void {
    expect(array_map(
        fn (LogLevel $level): int => $level->severity(),
        LogLevel::cases(),
    ))->toBe([0, 1, 2, 3, 4, 5, 6, 7]);
});

it('compares a level against a threshold', function (): void {
    expect(LogLevel::Error->isAtLeast(LogLevel::Error))->toBeTrue()
        ->and(LogLevel::Emergency->isAtLeast(LogLevel::Error))->toBeTrue()
        ->and(LogLevel::Warning->isAtLeast(LogLevel::Error))->toBeFalse();
});
