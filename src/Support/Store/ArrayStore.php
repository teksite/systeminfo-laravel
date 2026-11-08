<?php

namespace Teksite\SystemInfo\Support\Store;

use Teksite\SystemInfo\Contracts\StoreInterface;

/** In-process store: used by the CLI watcher, tests, and as a fallback. */
final class ArrayStore implements StoreInterface
{
    /** @var array<string, array{0: mixed, 1: int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        if (!isset($this->items[$key]))  return null;


        [$value, $expires] = $this->items[$key];

        if ($expires < time()) {
            unset($this->items[$key]);

            return null;
        }

        return $value;
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [$value, time() + max(1, $ttlSeconds)];
    }

    public function forget(string $key): void
    {
        unset($this->items[$key]);
    }

    public function atomic(string $key, callable $callback, int $waitSeconds = 2): mixed
    {
        return $callback();
    }
}
