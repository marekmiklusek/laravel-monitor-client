<?php

declare(strict_types=1);

namespace MarekMiklusek\MonitorClient\Support;

use Throwable;

use function app;
use function request;

use Illuminate\Contracts\Auth\Guard;

final readonly class ContextResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return [
            'url' => $this->url(),
            'method' => $this->method(),
            'user_id' => $this->userId(),
        ];
    }

    private function url(): ?string
    {
        try {
            return request()->fullUrl();
        } catch (Throwable) {
            return null;
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
}
