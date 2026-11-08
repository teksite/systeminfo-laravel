<?php

namespace Teksite\SystemInfo\Console;

use Illuminate\Console\Command;
use JsonException;
use Teksite\SystemInfo\Support\Format;
use Teksite\SystemInfo\SystemInfoManager;

final class WatchCommand extends Command
{
    protected $signature = 'system-info:watch
                            {--interval=2 : Seconds between samples}
                            {--duration=0 : Stop after N seconds (0 = until Ctrl+C)}
                            {--json : Emit one JSON object per line (NDJSON) instead of a text line}';

    protected $description = 'Live server metrics in the terminal. Every sample is also recorded into the history buffer';

    /**
     * @throws JsonException
     */
    public function handle(SystemInfoManager $manager): int
    {
        $interval = max(0.5, (float)$this->option('interval'));
        $duration = max(0, (int)$this->option('duration'));
        $started = microtime(true);
        $tick = $started;

        while (true) {
            $snapshot = $manager->metrics(fresh: true);

            $this->line($this->option('json')
                ? (string)json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                : $this->render($snapshot));

            $tick += $interval;

            if ($duration > 0 && ($tick - $started) > $duration) return self::SUCCESS;

            $sleep = $tick - microtime(true);

            if ($sleep > 0) usleep((int)($sleep * 1e6));
        }
    }

    /** @param array<string, mixed> $s */
    private function render(array $s): string
    {
        $parts = [
            date('H:i:s', intdiv((int)$s['time'], 1000)),
            'CPU '.Format::percent($s['cpu']['usage']),
            'RAM '.Format::percent($s['memory']['percent']).' ('.Format::bytes($s['memory']['used']).'/'.Format::bytes($s['memory']['total']).')',
            'NET ↓'.Format::rate($s['network']['rx_bytes_per_sec']).' ↑'.Format::rate($s['network']['tx_bytes_per_sec']),
            'DISK R '.Format::rate($s['disk_io']['read_bytes_per_sec']).' W '.Format::rate($s['disk_io']['write_bytes_per_sec']),
        ];

        if ($s['load'] !== null) $parts[] = 'LOAD '.$s['load']['1m'];

        return implode('  |  ', $parts);
    }
}
