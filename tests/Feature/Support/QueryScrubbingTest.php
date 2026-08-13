<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\MonitorConfig;

beforeEach(function (): void {
    Http::fake();

    Route::get('/crashes', function (): never {
        throw new RuntimeException('crashed');
    });
});

/**
 * @return array<string, mixed>
 */
function crashContext(string $uri, array $headers = []): array
{
    test()->get($uri, $headers);

    app(Monitor::class)->flush();

    $context = [];

    Http::assertSent(function ($request) use (&$context): bool {
        $occurrence = collect($request->data()['occurrences'])->firstWhere('type', 'exception');

        $context = $occurrence['context'] ?? [];

        return true;
    });

    return $context;
}

it('redacts a scrubbed query parameter in the url', function (): void {
    $context = crashContext('/crashes?api_key=k_live_SECRET&safe=ok');

    expect($context['url'])->toContain('safe=ok')
        ->and($context['url'])->not->toContain('k_live_SECRET')
        ->and(urldecode((string) $context['url']))->toContain('api_key=[REDACTED]');
});

it('redacts a scrubbed query parameter in the referer', function (): void {
    $context = crashContext('/crashes', ['Referer' => 'https://app.test/reset?token=t_SECRET']);

    expect($context['headers']['referer'])->not->toContain('t_SECRET')
        ->and(urldecode((string) $context['headers']['referer']))->toContain('token=[REDACTED]');
});

it('leaves a url without a query string untouched', function (): void {
    $context = crashContext('/crashes');

    expect($context['url'])->toBe('http://localhost/crashes');
});

it('leaves a url with only safe parameters untouched', function (): void {
    $context = crashContext('/crashes?page=2&sort=name');

    expect($context['url'])->toBe('http://localhost/crashes?page=2&sort=name');
});

it('never rewrites the path while redacting the query', function (): void {
    Route::get('/api_key=x/crashes', function (): never {
        throw new RuntimeException('crashed');
    });

    $context = crashContext('/api_key=x/crashes?api_key=k_SECRET');

    expect(urldecode((string) $context['url']))
        ->toBe('http://localhost/api_key=x/crashes?api_key=[REDACTED]');
});

it('redacts a secret nested in an array query parameter', function (): void {
    $context = crashContext('/crashes?user[password]=hunter2&user[name]=bob');

    expect(urldecode((string) $context['url']))
        ->toContain('user[password]=[REDACTED]')
        ->and(urldecode((string) $context['url']))->toContain('user[name]=bob');
});

it('redacts a query key regardless of its case', function (): void {
    $context = crashContext('/crashes?API_KEY=k_SECRET');

    expect((string) $context['url'])->not->toContain('k_SECRET');
});

it('keeps a fragment after redacting the query', function (): void {
    $context = crashContext('/crashes', ['Referer' => 'https://app.test/reset?token=t_SECRET#step-2']);

    expect((string) $context['headers']['referer'])
        ->not->toContain('t_SECRET')
        ->and((string) $context['headers']['referer'])->toEndWith('#step-2');
});

it('truncates a very long url but redacts it first', function (): void {
    $context = crashContext('/crashes?api_key=k_SECRET&blob='.str_repeat('x', 5000));

    expect(mb_strlen((string) $context['url']))->toBe(1000)
        ->and((string) $context['url'])->not->toContain('k_SECRET')
        ->and(urldecode((string) $context['url']))->toContain('api_key=[REDACTED]');
});

it('drops the whole query when the config cannot be resolved', function (): void {
    app()->bind(MonitorConfig::class, function (): never {
        throw new RuntimeException('container broken');
    });

    $context = crashContext('/crashes?api_key=k_live_SECRET');

    expect($context['url'])->toBe('http://localhost/crashes');
});

it('keeps the secret out of the whole payload', function (): void {
    test()->get('/crashes?password=hunter2', ['Referer' => 'https://app.test/x?secret=s_SECRET']);

    app(Monitor::class)->flush();

    Http::assertSent(function ($request): bool {
        $body = (string) json_encode($request->data());

        expect($body)->not->toContain('hunter2')
            ->and($body)->not->toContain('s_SECRET');

        return true;
    });
});
