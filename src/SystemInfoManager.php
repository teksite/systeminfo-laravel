<?php

namespace Teksite\SystemInfo;

use Closure;
use Teksite\SystemInfo\Contracts\CollectorInterface;
use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Contracts\StoreInterface;
use Teksite\SystemInfo\Metrics\ChartFormatter;
use Teksite\SystemInfo\Metrics\HistoryStore;
use Teksite\SystemInfo\Metrics\MetricsCollector;
use Teksite\SystemInfo\Support\Format;
use Teksite\SystemInfo\Support\Options;

/**
 * Single entry point of the package (also available as the `SystemInfo` facade).
 *
 * Two kinds of data, two strategies:
 *  - inventory (CPU model, OS, GPU, ...) never changes -> computed once, cached for `cache.inventory_ttl`;
 *  - live metrics change every second -> sampled incrementally and shared between callers.
 */
final class SystemInfoManager
{
    public const array SECTIONS = ['hardware', 'os', 'network', 'web_server', 'php', 'laravel', 'database'];

    /**
     * @param DriverInterface  $driver
     * @param StoreInterface   $store
     * @param Options          $options
     * @param MetricsCollector $collector
     * @param HistoryStore     $history
     * @param array            $collectors lazily built php/laravel/database collectors
     */
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly StoreInterface $store,
        private readonly Options $options,
        private readonly MetricsCollector $collector,
        private readonly HistoryStore $history,
        private readonly array $collectors = [],
    ) {
    }

    public function driver(): DriverInterface
    {
        return $this->driver;
    }

    public function options(): Options
    {
        return $this->options;
    }

    // ---- static ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function inventory(bool $fresh = false): array
    {
        if ($fresh)  $this->store->forget('inventory');

        return $this->remember('inventory', (int) $this->options->get('cache.inventory_ttl', 3600), fn (): array => $this->driver->inventory($fresh));
    }

    /**
     * Full report. Pass sections to compute only what you need, e.g. ['hardware', 'php'].
     *
     * @param  ?list<string>  $sections
     * @return array<string, mixed>
     */
    public function info(?array $sections = null, bool $fresh = false): array
    {
        $sections = array_values(array_intersect(self::SECTIONS, $sections ?? self::SECTIONS));

        $inventory = $this->inventory($fresh);
        $live = array_intersect(['hardware', 'os'], $sections) !== [] ? $this->metrics() : [];
        $report = [];

        foreach ($sections as $section) {
            $report[$section] = match ($section) {
                'hardware'   => [
                    'cpu'  => $inventory['cpu'] + ['usage_percent' => $live['cpu']['usage'] ?? null, 'load' => $live['load'] ?? null],
                    'ram'  => $live['memory'] ?? null,
                    'swap' => $live['swap'] ?? null,
                    'disk' => $live['disks'] ?? [],
                    'gpu'  => $inventory['gpu'],
                ],
                'os'         => $inventory['os'] + [
                    'upTime'         => Format::duration($live['uptime'] ?? null),
                    'uptime_seconds' => $live['uptime'] ?? null,
                ],
                'network'    => [
                    'localIp'    => $inventory['network']['local_ip'],
                    'publicIp'   => $this->publicIp(),
                    'interfaces' => $inventory['network']['interfaces'],
                ],
                'web_server' => [
                    'software'      => $this->driver->software(),
                    'php_sapi_name' => $this->driver->phpSapiName(),
                    'detect'        => $this->driver->webServer(),
                ],
                'database'   => $this->remember('database', (int) $this->options->get('cache.database_ttl', 300), fn (): array => $this->collect('database')),
                default      => $this->collect($section),
            };
        }

        $report['meta'] = ['timestamp' => time(), 'datetime' => date('Y-m-d H:i:s')];

        return $report;
    }

    /** Outbound request: off unless `network.public_ip.enabled`; cached, failures are cached briefly. */
    public function publicIp(): ?string
    {
        if (!$this->options->get('network.public_ip.enabled', false))   return null;

        $hit = $this->store->get('public_ip');

        if (is_array($hit) && array_key_exists('v', $hit))  return $hit['v'];

        $ttl = max(60, (int) $this->options->get('network.public_ip.ttl', 3600));
        $ip = $this->driver->publicIp();
        $this->store->put('public_ip', ['v' => $ip], $ip === null ? min(300, $ttl) : $ttl);

        return $ip;
    }

    // ---- live --------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function metrics(bool $fresh = false): array
    {
        return $this->collector->snapshot($fresh);
    }

    /** One Chart.js-shaped point built from the current snapshot. */
    public function chart(?string $labels = null, bool $fresh = false): array
    {
        return ChartFormatter::build([ChartFormatter::point($this->metrics($fresh))], $this->options, $labels);
    }

    /**
     * Chart.js-shaped history. The first call seeds the buffer so it is never empty.
     *
     * @param  ?int  $limit   newest N points
     * @param  ?int  $sinceMs only points newer than this epoch-milliseconds value
     */
    public function history(?int $limit = null, ?int $sinceMs = null, ?string $labels = null): array
    {
        $points = $this->history->points($limit, $sinceMs);

        if ($points === [] && $sinceMs === null) {
            $snapshot = $this->metrics();
            $points = $this->history->points($limit) ?: [ChartFormatter::point($snapshot)];
        }

        return ChartFormatter::build($points, $this->options, $labels);
    }

    // ---- internals ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function collect(string $name): array
    {
        $factory = $this->collectors[$name] ?? null;

        return $factory === null ? [] : $factory()->collect();
    }

    /**
     * Cache-aside with a lock (no stampede) and an envelope, so a legitimate `null`/`false` result is
     * not mistaken for a cache miss.
     */
    private function remember(string $key, int $ttl, callable $callback): mixed
    {
        $read = static fn (mixed $hit): bool => is_array($hit) && array_key_exists('v', $hit);

        if ($read($hit = $this->store->get($key))) {
            return $hit['v'];
        }

        return $this->store->atomic($key, function () use ($key, $ttl, $callback, $read): mixed {
            if ($read($hit = $this->store->get($key))) {
                return $hit['v'];
            }

            $value = $callback();
            $this->store->put($key, ['v' => $value], max(1, $ttl));

            return $value;
        }, 5);
    }
}
