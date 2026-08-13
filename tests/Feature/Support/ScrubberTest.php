<?php

declare(strict_types=1);

use MarekMiklusek\MonitorClient\Support\Scrubber;

it('redacts a password nested deep inside the context', function (): void {
    $scrubber = new Scrubber(['password', 'api_key']);

    $scrubbed = $scrubber->scrub([
        'url' => 'https://example.test/login',
        'payload' => [
            'user' => [
                'email' => 'marek@example.test',
                'password' => 'super-secret',
                'meta' => [
                    'api_key' => 'sk_live_123',
                ],
            ],
        ],
    ]);

    expect($scrubbed['payload']['user']['password'])->toBe('[REDACTED]')
        ->and($scrubbed['payload']['user']['meta']['api_key'])->toBe('[REDACTED]')
        ->and($scrubbed['payload']['user']['email'])->toBe('marek@example.test')
        ->and($scrubbed['url'])->toBe('https://example.test/login');
});

it('matches keys case insensitively', function (): void {
    $scrubber = new Scrubber(['password', 'authorization']);

    $scrubbed = $scrubber->scrub([
        'PASSWORD' => 'a',
        'Password' => 'b',
        'AuThOrIzAtIoN' => 'c',
    ]);

    expect($scrubbed)->toBe([
        'PASSWORD' => '[REDACTED]',
        'Password' => '[REDACTED]',
        'AuThOrIzAtIoN' => '[REDACTED]',
    ]);
});

it('matches a scrub key anywhere inside the key', function (): void {
    $scrubber = new Scrubber(['token', 'secret']);

    $scrubbed = $scrubber->scrub([
        '_token' => 'csrf-value',
        'api_token' => 'tok_live_123',
        'ACCESS_TOKEN' => 'at_live_123',
        'client_secret' => 'cs_live_123',
        'note' => 'kept',
    ]);

    expect($scrubbed)->toBe([
        '_token' => '[REDACTED]',
        'api_token' => '[REDACTED]',
        'ACCESS_TOKEN' => '[REDACTED]',
        'client_secret' => '[REDACTED]',
        'note' => 'kept',
    ]);
});

it('ignores an empty scrub key instead of redacting everything', function (): void {
    $scrubber = new Scrubber(['', 'password']);

    $scrubbed = $scrubber->scrub(['name' => 'bob', 'password' => 'hunter2']);

    expect($scrubbed)->toBe(['name' => 'bob', 'password' => '[REDACTED]']);
});

it('redacts the whole value even when it is an array', function (): void {
    $scrubber = new Scrubber(['secret']);

    $scrubbed = $scrubber->scrub([
        'secret' => ['nested' => 'value'],
    ]);

    expect($scrubbed['secret'])->toBe('[REDACTED]');
});

it('caps an oversized string value', function (): void {
    $scrubber = new Scrubber([]);

    $scrubbed = $scrubber->scrub(['blob' => str_repeat('x', 25_000)]);

    expect(mb_strlen((string) $scrubbed['blob']))
        ->toBe(10_000 + mb_strlen('... [truncated, 15000 chars omitted]'))
        ->and((string) $scrubbed['blob'])->toEndWith('... [truncated, 15000 chars omitted]');
});

it('leaves untouched data alone', function (): void {
    $scrubber = new Scrubber(['password']);

    $data = ['a' => 1, 'b' => [2, 3], 'c' => null, 'd' => true];

    expect($scrubber->scrub($data))->toBe($data);
});

it('stops recursing at the depth limit', function (): void {
    $scrubber = new Scrubber(['password']);

    $deep = ['password' => 'leaked'];

    for ($i = 0; $i < 20; $i++) {
        $deep = ['level' => $deep];
    }

    $scrubbed = $scrubber->scrub($deep);

    $flattened = json_encode($scrubbed);

    expect($flattened)->not->toContain('leaked');
});
