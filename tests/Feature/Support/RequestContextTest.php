<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use MarekMiklusek\MonitorClient\Monitor;
use MarekMiklusek\MonitorClient\MonitorConfig;
use MarekMiklusek\MonitorClient\PayloadBuilder;
use MarekMiklusek\MonitorClient\Support\ContextResolver;
use MarekMiklusek\MonitorClient\Transport\HttpTransport;
use MarekMiklusek\MonitorClient\Support\StackTraceFormatter;

beforeEach(function (): void {
    Http::fake();
});

/**
 * @return array<string, mixed>
 */
function reportedContext(bool $console): array
{
    $monitor = new Monitor(
        config: app(MonitorConfig::class),
        transport: new HttpTransport(app(MonitorConfig::class)),
        payloadBuilder: new PayloadBuilder,
        contextResolver: new ContextResolver($console),
        stackTraceFormatter: new StackTraceFormatter,
        environment: 'testing',
    );

    $monitor->report(new RuntimeException('boom'));
    $monitor->flush();

    $context = [];

    Http::assertSent(function ($request) use (&$context): bool {
        $context = $request->data()['occurrences'][0]['context'];

        return true;
    });

    return $context;
}

it('redacts a password from the request body but keeps the email', function (): void {
    $this->app['request']->merge(['password' => 'super-secret', 'email' => 'marek@example.test']);

    $this->app['request']->merge(['count' => 5, 'active' => true, 'nothing' => null]);

    $context = reportedContext(console: false);

    expect($context['input']['password'])->toBe('[REDACTED]')
        ->and($context['input']['email'])->toBe('marek@example.test')
        ->and($context['input']['count'])->toBe(5)
        ->and($context['input']['active'])->toBeTrue()
        ->and($context['input']['nothing'])->toBeNull();

    Http::assertSent(fn ($request): bool => ! str_contains((string) json_encode($request->data()), 'super-secret'));
});

it('never collects the authorization or cookie headers', function (): void {
    $this->app['request']->headers->set('Authorization', 'Bearer tajny-token');
    $this->app['request']->headers->set('Cookie', 'session=abc');
    $this->app['request']->headers->set('User-Agent', 'Pest');
    $this->app['request']->headers->set('X-Request-Id', 'req-1');

    $context = reportedContext(console: false);

    expect($context['headers'])->toHaveKey('user-agent')
        ->and($context['headers']['x-request-id'])->toBe('req-1')
        ->and($context['headers'])->not->toHaveKey('authorization')
        ->and($context['headers'])->not->toHaveKey('cookie');

    Http::assertSent(fn ($request): bool => ! str_contains((string) json_encode($request->data()), 'tajny-token'));
});

it('truncates long input values to 1000 characters', function (): void {
    $this->app['request']->merge(['note' => str_repeat('a', 2000)]);

    $context = reportedContext(console: false);

    expect(mb_strlen($context['input']['note']))->toBe(1000);
});

it('replaces uploaded files with a placeholder', function (): void {
    $file = UploadedFile::fake()->create('doc.pdf', 12);

    $this->app['request']->files->set('upload', $file);

    $context = reportedContext(console: false);

    expect($context['input']['upload'])->toBe(sprintf('[FILE doc.pdf, %d B]', $file->getSize()));
});

it('truncates arrays nested deeper than three levels', function (): void {
    $this->app['request']->merge(['l1' => ['l2' => ['l3' => ['l4' => 'deep']]]]);

    $context = reportedContext(console: false);

    expect($context['input']['l1']['l2']['l3'])->toBe('[TRUNCATED]');
});

it('collects at most 100 input keys', function (): void {
    $keys = [];

    foreach (range(1, 150) as $i) {
        $keys['key'.$i] = 'value';
    }

    $this->app['request']->merge($keys);

    $context = reportedContext(console: false);

    expect(count($context['input']))->toBe(100);
});

it('replaces non file objects in the input', function (): void {
    $this->app->instance('request', new Tests\Fakes\ObjectInputRequest);

    $context = reportedContext(console: false);

    expect($context['input']['object'])->toBe('[OBJECT]');
});

it('skips input collection when disabled', function (): void {
    config()->set('monitor.collect_input', false);

    $this->app['request']->merge(['password' => 'super-secret']);

    $context = reportedContext(console: false);

    expect($context)->not->toHaveKey('input');

    Http::assertSent(fn ($request): bool => ! str_contains((string) json_encode($request->data()), 'super-secret'));
});

it('describes the artisan command instead of input in console context', function (): void {
    $this->app['request']->server->set('argv', ['artisan', 'app:sync', '--password=secret', '--limit=5', 'file.csv', 42]);

    $context = reportedContext(console: true);

    expect($context)->not->toHaveKey('input')
        ->and($context['command']['name'])->toBe('app:sync')
        ->and($context['command']['arguments']['password'])->toBe('[REDACTED]')
        ->and($context['command']['arguments']['limit'])->toBe('5')
        ->and($context['command']['arguments'])->toContain('file.csv');

    Http::assertSent(fn ($request): bool => ! str_contains((string) json_encode($request->data()), 'secret'));
});

it('omits command arguments when input collection is disabled', function (): void {
    config()->set('monitor.collect_input', false);

    $this->app['request']->server->set('argv', ['artisan', 'app:sync', '--password=secret']);

    $context = reportedContext(console: true);

    expect($context['command'])->toBe(['name' => 'app:sync']);
});

it('cannot catch a secret hidden under an innocent key', function (): void {
    $this->app['request']->merge(['note' => 'my password is hunter2']);

    $context = reportedContext(console: false);

    expect($context['input']['note'])->toBe('my password is hunter2');
});

it('treats a console runtime without argv as a web request', function (): void {
    $this->app['request']->server->remove('argv');
    $this->app['request']->merge(['name' => 'bob']);

    $context = reportedContext(console: true);

    expect($context)->not->toHaveKey('command')
        ->and($context['input'])->toMatchArray(['name' => 'bob']);
});

it('omits the command when argv is unavailable', function (): void {
    $this->app['request']->server->remove('argv');

    $context = reportedContext(console: true);

    expect($context)->not->toHaveKey('command');
});

it('survives a missing request instance', function (): void {
    app()->forgetInstance('request');

    $context = new ContextResolver(false)->resolve();

    expect($context['url'])->toBeNull()
        ->and($context['headers'])->toBe([])
        ->and($context)->not->toHaveKey('input');
});
