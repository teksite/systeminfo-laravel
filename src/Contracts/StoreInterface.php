<?php

namespace Teksite\SystemInfo\Contracts;

/**
 * Minimal key/value + mutex abstraction. Keeps the metrics engine independent of Laravel's
 * cache so it can be unit-tested (and reused) without booting a framework.
 */
interface StoreInterface
{
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttlSeconds): void;

    public function forget(string $key): void;

    /**
     * Run $callback while holding a mutex. Implementations that cannot lock must simply
     * run the callback; a lock timeout must never turn into an error for the caller.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function atomic(string $key, callable $callback, int $waitSeconds = 2): mixed;
}
