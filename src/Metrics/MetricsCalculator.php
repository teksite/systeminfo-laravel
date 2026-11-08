<?php

namespace Teksite\SystemInfo\Metrics;

final class MetricsCalculator
{
    /**
     * @param array<string, mixed> $prev
     * @param array<string, mixed> $cur
     * @return array<string, mixed>
     */
    public static function build(array $prev, array $cur): array
    {
        $dt = max(0.0, (float)($cur['time'] ?? 0) - (float)($prev['time'] ?? 0));

        $netP = $prev['network'] ?? null;
        $netC = $cur['network'] ?? null;
        $ioP = $prev['disk_io'] ?? null;
        $ioC = $cur['disk_io'] ?? null;

        return [
            'time'      => (int)round(((float)($cur['time'] ?? microtime(true))) * 1000),
            'interval'  => round($dt, 3),
            'cpu'       => self::cpu($prev['cpu'] ?? null, $cur['cpu'] ?? null),
            'load'      => self::load($cur['load'] ?? null),
            'memory'    => self::memory($cur['memory'] ?? null),
            'swap'      => self::swap($cur['memory'] ?? null),
            'disks'     => array_values($cur['disks'] ?? []),
            'network'   => [
                'rx_bytes_per_sec' => self::rate($netP['rx'] ?? null, $netC['rx'] ?? null, $dt),
                'tx_bytes_per_sec' => self::rate($netP['tx'] ?? null, $netC['tx'] ?? null, $dt),
                'rx_total'         => $netC['rx'] ?? null,
                'tx_total'         => $netC['tx'] ?? null,
            ],
            'disk_io'   => [
                'read_bytes_per_sec'  => self::rate($ioP['read'] ?? null, $ioC['read'] ?? null, $dt),
                'write_bytes_per_sec' => self::rate($ioP['write'] ?? null, $ioC['write'] ?? null, $dt),
            ],
            'container' => $cur['container'] ?? null,
            'uptime'    => $cur['uptime'] ?? null,
        ];
    }

    public static function percent(float|int $used, float|int $total): float
    {
        if ($total <= 0) return 0.0;

        return round(self::clamp($used / $total * 100), 2);
    }

    /**
     * Per-second rate from a cumulative counter. A counter that went backwards (reset, wrap,
     * interface re-created) yields null: an honest gap in the chart beats a fake spike.
     */
    public static function rate(null|int|float $previous, null|int|float $current, float $seconds): ?float
    {
        if ($previous === null || $current === null || $seconds <= 0) return null;

        $delta = $current - $previous;

        return $delta < 0 ? null : round($delta / $seconds, 2);
    }

    /** @return array{usage: ?float, user: ?float, system: ?float, iowait: ?float, steal: ?float, cores: list<?float>} */
    public static function cpu(?array $prev, ?array $cur): array
    {
        $empty = ['usage' => null, 'user' => null, 'system' => null, 'iowait' => null, 'steal' => null, 'cores' => []];

        if ($cur === null) return $empty;

        // Drivers that can only report an instantaneous figure (Windows).
        if (array_key_exists('usage', $cur)) {
            $usage = $cur['usage'] === null ? null : round(self::clamp((float)$cur['usage']), 2);

            return ['usage' => $usage] + $empty;
        }

        if ($prev === null) return $empty;

        $total = self::ticks($prev['total'] ?? null, $cur['total'] ?? null);

        if ($total === null) return $empty;

        $cores = [];
        foreach (array_values($cur['cores'] ?? []) as $i => $core) {
            $cores[] = self::ticks($prev['cores'][$i] ?? null, $core)['usage'] ?? null;
        }

        return $total + ['cores' => $cores];
    }

    /** @return ?array{usage: float, user: float, system: float, iowait: float, steal: float} */
    private static function ticks(?array $prev, ?array $cur): ?array
    {
        if ($prev === null || $cur === null) return null;

        $delta = [];
        $sum = 0;

        foreach (['user', 'system', 'idle', 'iowait', 'steal'] as $key) {
            $delta[$key] = max(0, (int)($cur[$key] ?? 0) - (int)($prev[$key] ?? 0));
            $sum += $delta[$key];
        }

        if ($sum <= 0) return null;

        return [
            'usage'  => round(self::clamp((1 - ($delta['idle'] + $delta['iowait']) / $sum) * 100), 2),
            'user'   => round($delta['user'] / $sum * 100, 2),
            'system' => round($delta['system'] / $sum * 100, 2),
            'iowait' => round($delta['iowait'] / $sum * 100, 2),
            'steal'  => round($delta['steal'] / $sum * 100, 2),
        ];
    }

    /** @return ?array{'1m': float, '5m': float, '15m': float} */
    public static function load(?array $load): ?array
    {
        if ($load === null || count($load) < 3) return null;

        $load = array_values($load);

        return ['1m' => round((float)$load[0], 2), '5m' => round((float)$load[1], 2), '15m' => round((float)$load[2], 2)];
    }

    /** @return array{total: ?int, used: ?int, free: ?int, cached: ?int, percent: ?float} */
    public static function memory(?array $raw): array
    {
        $total = $raw['total'] ?? null;
        $available = $raw['available'] ?? null;

        if (!$total || $available === null) return ['total' => null, 'used' => null, 'free' => null, 'cached' => null, 'percent' => null];

        $available = min((int)$available, (int)$total);
        $used = (int)$total - $available;

        return [
            'total'   => (int)$total,
            'used'    => $used,
            'free'    => $available,
            'cached'  => isset($raw['cached']) ? (int)$raw['cached'] : null,
            'percent' => self::percent($used, $total),
        ];
    }

    /** @return array{total: ?int, used: ?int, free: ?int, percent: ?float} */
    public static function swap(?array $raw): array
    {
        $total = $raw['swap_total'] ?? null;

        if ($total === null) return ['total' => null, 'used' => null, 'free' => null, 'percent' => null];


        $total = (int)$total;
        $used = min($total, max(0, (int)($raw['swap_used'] ?? 0)));

        return ['total' => $total, 'used' => $used, 'free' => $total - $used, 'percent' => self::percent($used, $total)];
    }

    private static function clamp(float $value): float
    {
        return min(100.0, max(0.0, $value));
    }
}
