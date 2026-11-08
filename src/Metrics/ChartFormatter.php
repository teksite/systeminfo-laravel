<?php

namespace Teksite\SystemInfo\Metrics;

use Teksite\SystemInfo\Support\Options;

/**
 * Shapes metrics for Chart.js. Every chart is `{labels, datasets}` and can be handed straight to
 * `new Chart(ctx, {type: 'line', data: {labels, datasets}})`. A live update is the very same
 * structure with a single label/value, so a client only ever does:
 *
 *     chart.data.labels.push(c.labels[0]);
 *     c.datasets.forEach((d, i) => chart.data.datasets[i].data.push(d.data[0]));
 */
final class ChartFormatter
{
    public const array LABEL_FORMATS = ['time', 'iso', 'ms'];

    /**
     * Reduce a full snapshot to the compact point stored in history.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, int|float|null>
     */
    public static function point(array $snapshot): array
    {
        return [
            't'     => (int)$snapshot['time'],
            'cpu'   => $snapshot['cpu']['usage'] ?? null,
            'mem'   => $snapshot['memory']['percent'] ?? null,
            'swap'  => $snapshot['swap']['percent'] ?? null,
            'l1'    => $snapshot['load']['1m'] ?? null,
            'l5'    => $snapshot['load']['5m'] ?? null,
            'l15'   => $snapshot['load']['15m'] ?? null,
            'rx'    => $snapshot['network']['rx_bytes_per_sec'] ?? null,
            'tx'    => $snapshot['network']['tx_bytes_per_sec'] ?? null,
            'read'  => $snapshot['disk_io']['read_bytes_per_sec'] ?? null,
            'write' => $snapshot['disk_io']['write_bytes_per_sec'] ?? null,
        ];
    }

    /**
     * @param list<array<string, int|float|null>> $points oldest first
     * @return array<string, mixed>
     */
    public static function build(array $points, Options $options, ?string $labelFormat = null): array
    {
        $format = in_array($labelFormat, self::LABEL_FORMATS, true)
            ? $labelFormat
            : (string)$options->get('chart.labels', 'time');

        if (!in_array($format, self::LABEL_FORMATS, true)) {
            $format = 'time';
        }

        $colors = array_values((array)$options->get('chart.colors', []));

        $color = static fn(int $i): string => (string)($colors[$i % max(1, count($colors))] ?? '#3b82f6');

        $labels = array_map(static fn(array $p) => self::label($p['t'], $format), $points);

        $column = static fn(string $key): array => array_map(static fn(array $p) => $p[$key] ?? null, $points);

        $dataset = static fn(string $label, string $key, int $i): array => [
            'label'           => $label,
            'data'            => $column($key),
            'borderColor'     => $color($i),
            'backgroundColor' => $color($i).'33',
        ];

        $chart = static fn(string $title, string $unit, array $datasets, ?int $min = null, ?int $max = null): array => array_filter([
            'title'        => $title,
            'unit'         => $unit,
            'suggestedMin' => $min,
            'suggestedMax' => $max,
            'labels'       => $labels,
            'datasets'     => $datasets,
        ], static fn($v) => $v !== null);

        return [
            'meta'   => [
                'points'       => count($points),
                'from'         => $points[0]['t'] ?? null,
                'to'           => $points[count($points) - 1]['t'] ?? null,
                'label_format' => $format,
                'timezone'     => date_default_timezone_get(),
            ],
            'charts' => [
                'cpu'     => $chart('CPU usage', '%', [$dataset('CPU', 'cpu', 0)], 0, 100),
                'memory'  => $chart('Memory', '%', [$dataset('RAM', 'mem', 1), $dataset('Swap', 'swap', 3)], 0, 100),
                'load'    => $chart('Load average', 'load', [$dataset('1m', 'l1', 0), $dataset('5m', 'l5', 3), $dataset('15m', 'l15', 4)], 0),
                'network' => $chart('Network', 'B/s', [$dataset('Download', 'rx', 2), $dataset('Upload', 'tx', 0)], 0),
                'disk_io' => $chart('Disk I/O', 'B/s', [$dataset('Read', 'read', 2), $dataset('Write', 'write', 1)], 0),
            ],
        ];
    }

    private static function label(int $milliseconds, string $format): string|int
    {
        return match ($format) {
            'iso'   => date('c', intdiv($milliseconds, 1000)),
            'ms'    => $milliseconds,
            default => date('H:i:s', intdiv($milliseconds, 1000)),
        };
    }
}
