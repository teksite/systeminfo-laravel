<?php

namespace Teksite\SystemInfo\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Random\RandomException;
use Teksite\SystemInfo\Drivers\LinuxDriver;
use Teksite\SystemInfo\Drivers\WindowsDriver;
use Teksite\SystemInfo\Support\Options;
use Teksite\SystemInfo\Support\PowerShell;

final class DriversTest extends TestCase
{
    private string $root;

    /**
     * @throws RandomException
     */
    protected function setUp(): void
    {

        $this->root = sys_get_temp_dir(). DIRECTORY_SEPARATOR. 'sysinfo_fx_'. bin2hex(random_bytes(4));

        foreach (['/proc/net', '/etc', '/sys/fs/cgroup', '/tmp', '/var', '/usr',] as $dir) {
            mkdir($this->root . $dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function put(string $path, string $content): void
    {
        $fullPath = $this->root . str_replace('/', DIRECTORY_SEPARATOR, $path);

        $directory = dirname($fullPath);

        if (!is_dir($directory)){
            mkdir($directory, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    public function test_linux_sample_parsing(): void
    {
        $this->put('/proc/stat', "cpu  100 10 50 800 40 5 5 7 0 0\ncpu0 60 5 20 400 20 2 3 4 0 0\ncpu1 40 5 30 400 20 3 2 3 0 0\nintr 1 2 3\n");
        $this->put('/proc/meminfo', "MemTotal:       1000 kB\nMemFree:         100 kB\nMemAvailable:    400 kB\nBuffers:          10 kB\nCached:          200 kB\nSReclaimable:     20 kB\nSwapTotal:       500 kB\nSwapFree:        300 kB\nHugePages_Total:   0\nActive(anon):     5 kB\n");
        $this->put('/proc/loadavg', "0.50 0.25 0.10 1/100 999\n");
        $this->put('/proc/uptime', "90061.55 1000.00\n");
        $this->put('/proc/net/dev', "Inter-|   Receive |  Transmit\n face |bytes packets errs drop fifo frame compressed multicast|bytes packets errs drop fifo colls carrier compressed\n    lo: 999 1 0 0 0 0 0 0 999 1 0 0 0 0 0 0\n  eth0: 1000 1 0 0 0 0 0 0 2000 1 0 0 0 0 0 0\ndocker0: 5 1 0 0 0 0 0 0 5 1 0 0 0 0 0 0\n  eth1: 10 1 0 0 0 0 0 0 20 1 0 0 0 0 0 0\n");
        $this->put('/proc/diskstats', "   8 0 sda 1 0 100 0 1 0 200 0 0 0 0 0 0 0 0 0 0\n   8 1 sda1 1 0 100 0 1 0 200 0 0 0 0 0 0 0 0 0 0\n 259 0 nvme0n1 1 0 10 0 1 0 20 0 0 0 0 0 0 0 0 0 0\n   7 0 loop0 1 0 999 0 1 0 999 0 0 0 0 0 0 0 0 0 0\n 253 0 dm-0 1 0 999 0 1 0 999 0 0 0 0 0 0 0 0 0 0\n");
        $this->put('/proc/mounts', "/dev/sda1 / ext4 rw 0 0\n/dev/sda1 /etc/hosts ext4 rw 0 0\n/dev/sdb1 /tmp ext4 rw 0 0\nproc /proc proc rw 0 0\n/dev/loop3 /snap/core squashfs ro 0 0\n10.0.0.1:/x /tmp nfs rw 0 0\n");

        $d = new LinuxDriver(new Options(['disk' => ['paths' => ['/']]]), $this->root);
        $s = $d->sample();

        $this->assertSame(['user' => 110, 'system' => 60, 'idle' => 800, 'iowait' => 40, 'steal' => 7], $s['cpu']['total']);
        $this->assertCount(2, $s['cpu']['cores']);
        $this->assertSame([0.5, 0.25, 0.1], $s['load']);
        $this->assertSame(1000 * 1024, $s['memory']['total']);
        $this->assertSame(400 * 1024, $s['memory']['available']);
        $this->assertSame(200 * 1024, $s['memory']['swap_used']);
        $this->assertSame(['rx' => 1010, 'tx' => 2020], $s['network']);             // lo + docker0 excluded
        $this->assertSame(['read' => (100 + 10) * 512, 'write' => (200 + 20) * 512], $s['disk_io']); // whole disks only
        $this->assertSame(90061, $s['uptime']);
        $this->assertNull($s['container']);
        $this->assertCount(1, $s['disks']);
    }

    public function test_linux_mount_filtering_and_dedupe(): void
    {
        $this->put('/proc/mounts', "/dev/sda1 / ext4 rw 0 0\n/dev/sda1 /tmp ext4 rw 0 0\n/dev/sdb1 /var ext4 rw 0 0\nproc /proc proc rw 0 0\n/dev/loop3 /snap/core squashfs ro 0 0\n10.0.0.1:/x /usr nfs rw 0 0\n");
        $mounts = (new LinuxDriver(null, $this->root))->mountPoints();

        // sda1 mounted twice -> shortest path wins; squashfs/proc/nfs skipped.
        $this->assertSame(['/', '/var'], array_column($mounts, 'path'));
        $withNfs = (new LinuxDriver(['disk' => ['include_network_fs' => true]], $this->root))->mountPoints();
        $this->assertContains('/usr', array_column($withNfs, 'path'));
    }

    public function test_linux_cpuinfo_multi_socket_and_arm(): void
    {
        $block = static fn (int $i, int $phys, int $core): string => "processor\t: $i\nmodel name\t: Xeon E5\nphysical id\t: $phys\ncore id\t\t: $core\ncpu MHz\t\t: 2400.5\n\n";
        $d = new LinuxDriver(null, $this->root);

        // 2 sockets x 2 cores x 2 HT = 8 threads. The old regex reported per-socket values only.
        $text = $block(0, 0, 0) . $block(1, 0, 1) . $block(2, 1, 0) . $block(3, 1, 1) . $block(4, 0, 0) . $block(5, 0, 1) . $block(6, 1, 0) . $block(7, 1, 1);
        $cpu = $d->parseCpuInfo($text);
        $this->assertSame(8, $cpu['threads']);
        $this->assertSame(4, $cpu['cores']);
        $this->assertSame(2, $cpu['sockets']);
        $this->assertSame('Xeon E5', $cpu['model']);
        $this->assertSame(2401, $cpu['max_clock_mhz']);

        $arm = $d->parseCpuInfo("processor\t: 0\nBogoMIPS\t: 38.40\n\nprocessor\t: 1\nBogoMIPS\t: 38.40\n\nHardware\t: BCM2711\nModel\t\t: Raspberry Pi 4\n");
        $this->assertSame(2, $arm['threads']);
        $this->assertSame(2, $arm['cores']);
        $this->assertSame('BCM2711', $arm['model']);
    }

    public function test_linux_os_release_with_awkward_values(): void
    {
        // parse_ini_file() fails on unquoted `(` `!` `$`; the hand parser must not.
        $this->put('/etc/os-release', "NAME=\"Foo Linux\"\nPRETTY_NAME=\"Foo Linux 1.0 (Bar) \$x!\"\nVERSION_ID=1.0\nID=foo\nHOME_URL=https://x.test/?a=1&b=(2)\n");
        $v = (new LinuxDriver(null, $this->root))->osVersion();
        $this->assertSame('Foo Linux 1.0 (Bar) $x!', $v['name']);
        $this->assertSame('1.0', $v['version']);
        $this->assertSame('foo', $v['id']);
    }

    public function test_linux_container_limits(): void
    {
        $this->put('/proc/meminfo', "MemTotal: 8000000 kB\nMemAvailable: 4000000 kB\n");
        $this->put('/sys/fs/cgroup/memory.max', "536870912\n");
        $this->put('/sys/fs/cgroup/memory.current', "300000000\n");
        $this->put('/sys/fs/cgroup/memory.stat', "anon 1\ninactive_file 100000000\n");
        $this->put('/sys/fs/cgroup/cpu.max', "150000 100000\n");

        $d = new LinuxDriver(null, $this->root);
        $m = $d->sample()['container']['memory'];
        $this->assertSame(536870912, $m['limit']);
        $this->assertSame(200000000, $m['used']);           // usage - inactive_file, like `docker stats`
        $this->assertSame(1.5, $d->parseCpuInfo("processor : 0\n")['limit_cores']);

        $this->put('/sys/fs/cgroup/memory.max', "max\n");   // unlimited -> no container block
        $this->assertNull($d->sample()['container']);
    }

    public function test_windows_driver_runs_one_powershell_call_per_sample(): void
    {
        $ps = new class extends PowerShell {
            public int $calls = 0;
            public function json(string $script): ?array
            {
                $this->calls++;

                // Single-element arrays collapsed to objects: exactly what PowerShell 5.1 does.
                return str_contains($script, 'Win32_PerfRawData_Tcpip')
                    ? [
                        'cpu' => 37.5, 'mem_total' => 16000000, 'mem_free' => 4000000, 'swap_total' => 2048, 'swap_used' => 512, 'boot' => time() - 7200,
                        'disks' => ['id' => 'C:', 'size' => 1000, 'free' => 250, 'fs' => 'NTFS'],
                        'net' => [['name' => 'Intel[R] Ethernet', 'rx' => 1000, 'tx' => 500], ['name' => 'Loopback Pseudo-Interface 1', 'rx' => 9, 'tx' => 9]],
                        'io_read' => 4096, 'io_write' => 8192,
                    ]
                    : [
                        'cpu' => ['name' => 'Intel Core i7', 'cores' => 8, 'threads' => 16, 'max_clock' => 3600],
                        'gpu' => ['name' => 'RTX', 'memory' => 4293918720, 'driver' => '31.0'],
                        'os' => ['caption' => 'Microsoft Windows 11 Pro', 'version' => '10.0.22631', 'build' => '22631', 'arch' => '64-bit', 'product_type' => 1, 'boot' => time() - 7200],
                        'timezone' => 'Iran Standard Time',
                    ];
            }
        };

        $d = new WindowsDriver(null, $ps);
        $s = $d->sample();

        $this->assertSame(37.5, $s['cpu']['usage']);
        $this->assertSame(16000000 * 1024, $s['memory']['total']);
        $this->assertSame(['rx' => 1000, 'tx' => 500], $s['network']);       // loopback excluded
        $this->assertSame('C:', $s['disks'][0]['path']);
        $this->assertSame(75.0, $s['disks'][0]['percent']);
        $this->assertEqualsWithDelta(7200, $s['uptime'], 2);
        $this->assertSame(1, $ps->calls);

        $this->assertSame('Intel Core i7', $d->cpu()['model']);
        $this->assertNull($d->cpu()['load']);
        $this->assertSame(2, $ps->calls);                                      // one more for the whole inventory
        $this->assertFalse($d->version()['is_server']);
        $this->assertSame('Iran Standard Time', $d->timeZone());
        $this->assertSame(2, $ps->calls);                                      // everything else memoised
    }


    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $directory,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
