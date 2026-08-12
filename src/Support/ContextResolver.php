<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use Throwable;

use function app;
use function request;

use Illuminate\Contracts\Auth\Guard;
use MarekMiklusek\MonitorClient\MonitorConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ContextResolver
{
    private const array HEADER_WHITELIST = [
        'accept',
        'content-type',
        'user-agent',
        'referer',
        'origin',
        'x-request-id',
    ];

    private const array URL_HEADERS = ['referer', 'origin'];

    private const int MAX_DEPTH = 3;

    private const int MAX_KEYS = 100;

    private const int MAX_VALUE_LENGTH = 1000;

    public function __construct(
        private ?bool $runningInConsole = null,
    ) {
        // ...
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $context = [
            'url' => $this->url(),
            'method' => $this->method(),
            'user_id' => $this->userId(),
            'headers' => $this->headers(),
        ];

        if ($this->isConsole()) {
            $command = $this->command();

            if ($command !== null) {
                $context['command'] = $command;
            }

            return $context;
        }

        $input = $this->input();

        if ($input !== null) {
            $context['input'] = $input;
        }

        return $context;
    }

    private function url(): ?string
    {
        try {
            return mb_substr($this->scrubQuery(request()->fullUrl()), 0, self::MAX_VALUE_LENGTH);
        } catch (Throwable) {
            return null;
        }
    }

    private function scrubQuery(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        parse_str($query, $parameters);

        $scrubbed = new Scrubber($this->scrubKeys())->scrub($parameters);

        if ($scrubbed === $parameters) {
            return $url;
        }

        [$base, $rest] = explode('?', $url, 2);

        $fragment = explode('#', $rest, 2)[1] ?? null;

        return $base.'?'.http_build_query($scrubbed).($fragment === null ? '' : '#'.$fragment);
    }

    /**
     * @return array<int, string>
     */
    private function scrubKeys(): array
    {
        try {
            return app(MonitorConfig::class)->scrubKeys();
        } catch (Throwable) {
            return [];
        }
    }

    private function method(): ?string
    {
        try {
            return request()->method();
        } catch (Throwable) {
            return null;
        }
    }

    private function userId(): int|string|null
    {
        try {
            if (! app()->bound(Guard::class)) {
                return null;
            }

            $id = app(Guard::class)->id();

            return is_int($id) || is_string($id) ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        try {
            $headers = [];

            foreach (self::HEADER_WHITELIST as $name) {
                $value = request()->headers->get($name);

                if (is_string($value)) {
                    $headers[$name] = mb_substr(
                        in_array($name, self::URL_HEADERS, true) ? $this->scrubQuery($value) : $value,
                        0,
                        self::MAX_VALUE_LENGTH,
                    );
                }
            }

            return $headers;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function input(): ?array
    {
        try {
            if (! $this->collectInput()) {
                return null;
            }

            $budget = self::MAX_KEYS;

            return $this->sanitize(request()->all(), 1, $budget);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function command(): ?array
    {
        try {
            $includeArguments = $this->collectInput();

            $argv = request()->server('argv');

            if (! is_array($argv)) {
                return null;
            }

            $name = isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null;

            $command = ['name' => $name];

            if ($includeArguments) {
                $command['arguments'] = $this->arguments(array_slice($argv, 2));
            }

            return $command;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<array-key, mixed>  $argv
     * @return array<array-key, string>
     */
    private function arguments(array $argv): array
    {
        $arguments = [];

        foreach ($argv as $argument) {
            if (! is_string($argument)) {
                continue;
            }

            if (preg_match('/^--([^=]+)=(.*)$/', $argument, $matches) === 1) {
                $arguments[$matches[1]] = mb_substr($matches[2], 0, self::MAX_VALUE_LENGTH);

                continue;
            }

            $arguments[] = mb_substr($argument, 0, self::MAX_VALUE_LENGTH);
        }

        return $arguments;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sanitize(array $data, int $depth, int &$budget): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if ($budget <= 0) {
                break;
            }

            $budget--;

            $clean[$key] = $this->sanitizeValue($value, $depth, $budget);
        }

        return $clean;
    }

    private function sanitizeValue(mixed $value, int $depth, int &$budget): mixed
    {
        if ($value instanceof UploadedFile) {
            return sprintf('[FILE %s, %d B]', $value->getClientOriginalName(), (int) $value->getSize());
        }

        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH
                ? '[TRUNCATED]'
                : $this->sanitize($value, $depth + 1, $budget);
        }

        if (is_string($value)) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        return '[OBJECT]';
    }

    private function collectInput(): bool
    {
        try {
            return app(MonitorConfig::class)->collectInput();
        } catch (Throwable) {
            return true;
        }
    }

    private function isConsole(): bool
    {
        try {
            return $this->runningInConsole ?? app()->runningInConsole();
        } catch (Throwable) {
            return true;
        }
    }
}
