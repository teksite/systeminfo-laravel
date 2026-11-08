<?php

namespace Teksite\SystemInfo\Metrics;

use Teksite\SystemInfo\Contracts\StoreInterface;
use Teksite\SystemInfo\Support\Options;

/**
 * Bounded ring buffer of compact points, kept in the cache. It is what lets a dashboard draw the
 * last N minutes the moment it opens, and then continue with live points.
 *
 * Memory is bounded by `history.points`; writes are throttled to one per `history.resolution`
 * seconds no matter how many clients poll.
 */
final class HistoryStore
{
    private const string KEY = 'history';

    public function __construct(private readonly StoreInterface $store, private readonly Options $options) {}

    /** @param array<string, mixed> $snapshot */
    public function record(array $snapshot): void
    {
        if (!$this->options->get('history.enabled', true)) return;

        $point = ChartFormatter::point($snapshot);
        $resolutionMs = max(0.0, (float)$this->options->get('history.resolution', 2.0)) * 1000;
        $max = max(1, (int)$this->options->get('history.points', 300));

        $this->store->atomic(self::KEY.':write', function () use ($point, $resolutionMs, $max): void {
            $points = $this->store->get(self::KEY);
            $points = is_array($points) ? $points : [];

            $last = $points === [] ? null : $points[array_key_last($points)];

            if ($last !== null && ($point['t'] - $last['t']) < $resolutionMs) return;

            $points[] = $point;

            if (count($points) > $max) $points = array_slice($points, -$max);

            $this->store->put(self::KEY, $points, (int)max(60, ceil($max * $resolutionMs / 1000 * 2)));
        }, 1);
    }

    /**
     * @param  ?int $limit   keep only the newest N points
     * @param  ?int $sinceMs keep only points strictly newer than this timestamp (incremental polling)
     * @return list<array<string, int|float|null>>
     */
    public function points(?int $limit = null, ?int $sinceMs = null): array
    {
        $points = $this->store->get(self::KEY);
        $points = is_array($points) ? array_values($points) : [];

        if ($sinceMs !== null) {
            $points = array_values(array_filter($points, static fn(array $p): bool => $p['t'] > $sinceMs));
        }

        if ($limit !== null && $limit > 0 && count($points) > $limit) {
            $points = array_slice($points, -$limit);
        }

        return $points;
    }

    public function clear(): void
    {
        $this->store->forget(self::KEY);
    }
}
