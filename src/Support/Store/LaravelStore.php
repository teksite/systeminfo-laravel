<?php

namespace Teksite\SystemInfo\Support\Store;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Teksite\SystemInfo\Contracts\StoreInterface;

final class LaravelStore implements StoreInterface
{
    public function __construct(private readonly Repository $cache, private readonly string $prefix = 'system-info:') {}

    public function get(string $key): mixed
    {
        return $this->cache->get($this->prefix.$key);
    }

    public function put(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->cache->put($this->prefix.$key, $value, max(1, $ttlSeconds));
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->prefix.$key);
    }

    public function atomic(string $key, callable $callback, int $waitSeconds = 2): mixed
    {
        $store = method_exists($this->cache, 'getStore') ? $this->cache->getStore() : null;

        if (!$store instanceof LockProvider)  return $callback();

        try {
            return $store->lock($this->prefix.$key.':lock', 10)->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            // Never fail a request just because another worker is slow: compute without the lock.
            return $callback();
        }
    }
}
