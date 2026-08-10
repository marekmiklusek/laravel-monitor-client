<?php

declare(strict_types=1);

use Tests\Fakes\BrokenRequest;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\Support\ContextResolver;

it('resolves url, method and user id', function (): void {
    $context = (new ContextResolver)->resolve();

    expect($context)->toHaveKeys(['url', 'method', 'user_id'])
        ->and($context['url'])->toBeString()
        ->and($context['method'])->toBeString();
});

it('returns a null user id when no guard is bound', function (): void {
    app()->forgetInstance(Guard::class);
    app()->offsetUnset(Guard::class);

    expect((new ContextResolver)->resolve()['user_id'])->toBeNull();
});

it('reads the id from the bound guard', function (): void {
    app()->instance(Guard::class, new class
    {
        public function id(): int
        {
            return 42;
        }
    });

    expect((new ContextResolver)->resolve()['user_id'])->toBe(42);
});

it('accepts a string user id', function (): void {
    app()->instance(Guard::class, new class
    {
        public function id(): string
        {
            return 'uuid-1';
        }
    });

    expect((new ContextResolver)->resolve()['user_id'])->toBe('uuid-1');
});

it('discards a user id that is neither int nor string', function (): void {
    app()->instance(Guard::class, new class
    {
        /**
         * @return array<int, string>
         */
        public function id(): array
        {
            return ['not', 'an', 'id'];
        }
    });

    expect((new ContextResolver)->resolve()['user_id'])->toBeNull();
});

it('survives a guard that throws', function (): void {
    app()->instance(Guard::class, new class
    {
        public function id(): never
        {
            throw new RuntimeException('no session');
        }
    });

    expect((new ContextResolver)->resolve()['user_id'])->toBeNull();
});

it('survives a request that cannot report its url or method', function (): void {
    app()->instance('request', new BrokenRequest);

    $context = (new ContextResolver)->resolve();

    expect($context['url'])->toBeNull()
        ->and($context['method'])->toBeNull();
});

it('still reports an exception when the context cannot be resolved', function (): void {
    Http::fake();

    app()->instance('request', new BrokenRequest);

    $monitor = app(Monitor::class);
    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    Http::assertSent(function ($request): bool {
        $context = $request->data()['occurrences'][0]['context'];

        expect($context['url'])->toBeNull()
            ->and($context['method'])->toBeNull();

        return true;
    });
});
