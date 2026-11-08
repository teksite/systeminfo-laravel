<?php

declare(strict_types=1);

namespace Teksite\SystemInfo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Teksite\SystemInfo\Metrics\ChartFormatter;
use Teksite\SystemInfo\Metrics\HistoryStore;
use Teksite\SystemInfo\Metrics\MetricsCalculator;
use Teksite\SystemInfo\Metrics\MetricsCollector;
use Teksite\SystemInfo\Support\Format;
use Teksite\SystemInfo\Support\Glob;
use Teksite\SystemInfo\Support\Options;
use Teksite\SystemInfo\Support\SafeExecutor;
use Teksite\SystemInfo\Support\Store\ArrayStore;
use Teksite\SystemInfo\SystemInfoManager;

final class CoreTest extends TestCase
{
    public function test_options_deep_merge_keeps_sibling_defaults(): void
    {
        $o = new Options(['live' => ['min_interval' => 5], 'http' => ['allowed_ips' => ['10.0.0.0/8']], 'history' => []]);

        $this->assertSame(5, $o->get('live.min_interval'));
        $this->assertSame(250, $o->get('live.sample_window_ms'));
        $this->assertSame(['10.0.0.0/8'], $o->get('http.allowed_ips'));
        $this->assertSame(300, $o->get('history.points'));
        $this->assertSame('x', $o->get('nope.nope', 'x'));
    }

    public function test_glob_and_format(): void
    {
        $this->assertTrue(Glob::match('docker*', 'Docker0'));
        $this->assertTrue(Glob::match('*Virtual Switch*', 'Hyper-V Virtual Switch #2'));
        $this->assertFalse(Glob::match('lo', 'eth0'));
        $this->assertSame('1.5 KB', Format::bytes(1536));
        $this->assertSame('n/a', Format::bytes(null));
        $this->assertSame('1d 1h 1m', Format::duration(90061));
    }

    public function test_cpu_rates_and_counter_reset(): void
    {
        $p = ['total' => ['user' => 100, 'system' => 50, 'idle' => 800, 'iowait' => 50, 'steal' => 0], 'cores' => [['user' => 100, 'system' => 50, 'idle' => 800, 'iowait' => 50, 'steal' => 0]]];
        $c = ['total' => ['user' => 130, 'system' => 60, 'idle' => 860, 'iowait' => 50, 'steal' => 0], 'cores' => [['user' => 130, 'system' => 60, 'idle' => 860, 'iowait' => 50, 'steal' => 0]]];

        $cpu = MetricsCalculator::cpu($p, $c);
        $this->assertSame(40.0, $cpu['usage']);   // 40 busy of 100 ticks
        $this->assertSame(30.0, $cpu['user']);
        $this->assertSame([40.0], $cpu['cores']);

        $this->assertSame(55.0, MetricsCalculator::cpu(null, ['usage' => 55])['usage']);
        $this->assertNull(MetricsCalculator::cpu(null, ['total' => []])['usage']);

        $this->assertSame(500.0, MetricsCalculator::rate(1000, 2000, 2.0));
        $this->assertNull(MetricsCalculator::rate(2000, 1000, 1.0));   // counter reset -> gap, not a spike
        $this->assertNull(MetricsCalculator::rate(1, 2, 0.0));
    }

    public function test_memory_and_swap(): void
    {
        $m = MetricsCalculator::memory(['total' => 1000, 'available' => 250, 'cached' => 100]);
        $this->assertSame(750, $m['used']);
        $this->assertSame(75.0, $m['percent']);
        $this->assertNull(MetricsCalculator::memory(null)['percent']);
        $this->assertSame(0.0, MetricsCalculator::swap(['swap_total' => 0])['percent']);
        $this->assertSame(50.0, MetricsCalculator::swap(['swap_total' => 100, 'swap_used' => 50])['percent']);
    }

    public function test_chart_format_is_chartjs_shaped(): void
    {
        $o = new Options();
        $points = [
            ['t' => 1_700_000_000_000, 'cpu' => 10.0, 'mem' => 50.0, 'swap' => 0.0, 'l1' => 0.1, 'l5' => 0.2, 'l15' => 0.3, 'rx' => 100.0, 'tx' => 50.0, 'read' => 0.0, 'write' => 5.0],
            ['t' => 1_700_000_002_000, 'cpu' => null, 'mem' => 51.0, 'swap' => 0.0, 'l1' => 0.1, 'l5' => 0.2, 'l15' => 0.3, 'rx' => null, 'tx' => 50.0, 'read' => 0.0, 'write' => 5.0],
        ];

        $c = ChartFormatter::build($points, $o, 'ms');
        $cpu = $c['charts']['cpu'];

        $this->assertSame(2, $c['meta']['points']);
        $this->assertSame([1_700_000_000_000, 1_700_000_002_000], $cpu['labels']);
        $this->assertSame([10.0, null], $cpu['datasets'][0]['data']);
        $this->assertSame(100, $cpu['suggestedMax']);
        $this->assertCount(2, $c['charts']['network']['datasets']);
        $this->assertCount(2, $c['charts']['network']['datasets'][0]['data']);
        $this->assertMatchesRegularExpression('/^\d\d:\d\d:\d\d$/', ChartFormatter::build($points, $o)['charts']['cpu']['labels'][0]);
        $this->assertArrayNotHasKey('suggestedMax', $c['charts']['network']);
    }

    public function test_history_is_bounded_and_throttled(): void
    {
        $o = new Options(['history' => ['points' => 3, 'resolution' => 2.0]]);
        $h = new HistoryStore(new ArrayStore(), $o);
        $snap = static fn (int $ms): array => ['time' => $ms, 'cpu' => ['usage' => 1.0], 'memory' => ['percent' => 2.0]];

        $h->record($snap(1_000));
        $h->record($snap(1_500));   // < resolution -> dropped
        foreach ([3_000, 5_000, 7_000, 9_000] as $ms) {
            $h->record($snap($ms));
        }

        $this->assertSame([5_000, 7_000, 9_000], array_column($h->points(), 't'));
        $this->assertSame([9_000], array_column($h->points(null, 7_000), 't'));
        $this->assertSame([7_000, 9_000], array_column($h->points(2), 't'));
    }

    public function test_collector_shares_samples_and_diffs_against_previous(): void
    {
        $now = microtime(true);
        $driver = new FakeDriver([
            ['time' => $now,       'cpu' => ['total' => ['user' => 0, 'system' => 0, 'idle' => 100, 'iowait' => 0, 'steal' => 0], 'cores' => []], 'network' => ['rx' => 0, 'tx' => 0], 'memory' => ['total' => 100, 'available' => 50], 'disks' => []],
            ['time' => $now + 1.0, 'cpu' => ['total' => ['user' => 50, 'system' => 0, 'idle' => 150, 'iowait' => 0, 'steal' => 0], 'cores' => []], 'network' => ['rx' => 1000, 'tx' => 0], 'memory' => ['total' => 100, 'available' => 50], 'disks' => []],
            ['time' => $now + 2.0, 'cpu' => ['total' => ['user' => 50, 'system' => 0, 'idle' => 250, 'iowait' => 0, 'steal' => 0], 'cores' => []], 'network' => ['rx' => 3000, 'tx' => 0], 'memory' => ['total' => 100, 'available' => 50], 'disks' => []],
        ]);
        $o = new Options(['live' => ['sample_window_ms' => 0, 'min_interval' => 60]]);
        $collector = new MetricsCollector($driver, new ArrayStore(), $o, new HistoryStore(new ArrayStore(), $o));

        $first = $collector->snapshot();
        $this->assertSame(2, $driver->calls);                // cold start: two samples
        $this->assertSame(50.0, $first['cpu']['usage']);
        $this->assertSame(1000.0, $first['network']['rx_bytes_per_sec']);

        $this->assertSame($first, $collector->snapshot());   // shared within min_interval
        $this->assertSame(2, $driver->calls);

        $warm = $collector->snapshot(fresh: true);           // warm: exactly ONE new sample, diffed vs the cached one
        $this->assertSame(3, $driver->calls);
        $this->assertSame(0.0, $warm['cpu']['usage']);       // 100 idle ticks, 0 busy
        $this->assertSame(2000.0, $warm['network']['rx_bytes_per_sec']);
    }

    public function test_manager_info_and_chart(): void
    {
        $now = microtime(true);
        $driver = new FakeDriver([['time' => $now, 'cpu' => ['usage' => 20.0], 'memory' => ['total' => 100, 'available' => 40], 'disks' => [], 'uptime' => 90061]]);
        $store = new ArrayStore();
        $o = new Options(['live' => ['sample_window_ms' => 0]]);
        $history = new HistoryStore($store, $o);
        $m = new SystemInfoManager($driver, $store, $o, new MetricsCollector($driver, $store, $o, $history), $history, [
            'php' => static fn () => new class implements \Teksite\SystemInfo\Contracts\CollectorInterface {
                public function collect(): array { return ['version' => PHP_VERSION]; }
            },
        ]);

        $info = $m->info(['hardware', 'os', 'php']);
        $this->assertSame('Fake CPU', $info['hardware']['cpu']['model']);
        $this->assertSame(20.0, $info['hardware']['cpu']['usage_percent']);
        $this->assertSame('1d 1h 1m', $info['os']['upTime']);
        $this->assertSame(PHP_VERSION, $info['php']['version']);
        $this->assertArrayNotHasKey('database', $info);
        $this->assertNull($m->publicIp());                  // outbound call is opt-in

        $calls = $driver->calls;
        $m->inventory();
        $this->assertSame($calls, $driver->calls);          // inventory comes from cache, no new sample

        $h = $m->history();
        $this->assertGreaterThanOrEqual(1, $h['meta']['points']);
        $this->assertSame([20.0], array_slice($h['charts']['cpu']['datasets'][0]['data'], 0, 1));
    }

    public function test_safe_executor(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX only');
        }

        $this->assertSame('a b;c', SafeExecutor::run(['echo', 'a b;c']));     // no shell: metacharacters are inert
        $this->assertNull(SafeExecutor::run(['sleep', '5'], 0.3));             // killed on timeout
        $this->assertNull(SafeExecutor::run(['/nonexistent/binary']));
        $this->assertTrue(SafeExecutor::commandExists('sh'));
        $this->assertFalse(SafeExecutor::commandExists('sh; rm -rf /'));
    }
}
