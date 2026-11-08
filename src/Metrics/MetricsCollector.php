<?php

namespace Teksite\SystemInfo\Metrics;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Contracts\StoreInterface;
use Teksite\SystemInfo\Support\Options;

/**
 * Produces the live snapshot.
 *
 * CPU %, network and disk throughput are *rates*, so they need two samples. A stateless PHP
 * request would have to sleep to take both; instead the previous sample is kept in the cache
 * and every request only takes ONE new sample and diffs it against the last one:
 *
 *   request N   : sample -> diff vs cached sample(N-1) -> cache sample(N) -> done (no sleeping)
 *   cold start  : sample, wait `live.sample_window_ms`, sample again (once)
 *
 * On top of that, results are shared: any number of dashboards polling at once cost one sample
 * per `live.min_interval`, guarded by a lock with double-checked caching (no stampede).
 */
final class MetricsCollector
{
    private const string LAST = 'live:last';

    private const string PREVIOUS = 'live:previous';

    public function __construct(
        private readonly DriverInterface $driver,
        private readonly StoreInterface $store,
        private readonly Options $options,
        private readonly ?HistoryStore $history = null,
    ) {
    }

    /**
     * @param  bool  $fresh  skip the min_interval share and force a new sample
     * @return array<string, mixed>
     */
    public function snapshot(bool $fresh = false): array
    {
        if (!$fresh && ($cached = $this->cached()) !== null) return $cached;

        return $this->store->atomic('live', function () use ($fresh): array {
            // Double-checked: another worker may have produced a snapshot while we waited for the lock.
            if (!$fresh && ($cached = $this->cached()) !== null)   return $cached;

            return $this->compute();
        }, 2);
    }

    /** @return ?array<string, mixed> */
    private function cached(): ?array
    {
        $last = $this->store->get(self::LAST);
        $minMs = max(0.0, (float) $this->options->get('live.min_interval', 1.0)) * 1000;

        if (is_array($last) && isset($last['time']) && (microtime(true) * 1000 - $last['time']) < $minMs)return $last;

        return null;
    }

    /** @return array<string, mixed> */
    private function compute(): array
    {
        $current = $this->driver->sample();
        $previous = $this->store->get(self::PREVIOUS);
        $maxAge = max(1.0, (float) $this->options->get('live.max_sample_age', 15));

        $usable = is_array($previous)
            && isset($previous['time'])
            && $current['time'] > $previous['time']
            && ($current['time'] - $previous['time']) <= $maxAge;

        if (!$usable) {
            $previous = $current;
            usleep(max(0, (int) $this->options->get('live.sample_window_ms', 250)) * 1000);
            $current = $this->driver->sample();
        }

        $snapshot = MetricsCalculator::build($previous, $current);

        $this->store->put(self::PREVIOUS, $current, (int) ceil($maxAge) + 5);
        $this->store->put(self::LAST, $snapshot, 30);
        $this->history?->record($snapshot);

        return $snapshot;
    }
}
