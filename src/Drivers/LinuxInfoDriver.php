<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\SystemInformationProvider;

class LinuxInfoDriver  extends SystemInformationProvider {


    public function getOsInfo(): array
    {
        $pretty  = $this->exec("cat /etc/os-release | grep PRETTY_NAME | cut -d= -f2 | tr -d '\"'");
        $kernel  = $this->exec('uname -r');
        $machine = $this->exec('uname -m');
        $uptime  = $this->exec('uptime -p');

        return [
            'platform'     => 'Linux',
            'distribution' => $pretty  ?? php_uname('s'),
            'kernel'       => $kernel  ?? php_uname('r'),
            'architecture' => $machine ?? php_uname('m'),
            'hostname'     => gethostname() ?: 'unknown',
            'uptime'       => $uptime  ?? null,
        ];
    }

    public function getCpuInfo(): array
    {
        $model    = $this->exec("grep -m1 'model name' /proc/cpuinfo | awk -F': ' '{print $2}'");
        $cores    = $this->exec("grep -c '^processor' /proc/cpuinfo");
        $loadRaw  = $this->exec('cat /proc/loadavg');

        $load1 = $load5 = $load15 = null;
        if ($loadRaw) {
            [$load1, $load5, $load15] = array_pad(explode(' ', $loadRaw), 3, null);
        }

        // CPU usage % via /proc/stat snapshot
        $usage = $this->getCpuUsagePercent();

        return [
            'model'     => $model ?? 'unknown',
            'cores'     => $cores ? (int) $cores : null,
            'usage_pct' => $usage,
            'load_avg'  => [
                '1min'  => $load1  !== null ? (float) $load1  : null,
                '5min'  => $load5  !== null ? (float) $load5  : null,
                '15min' => $load15 !== null ? (float) $load15 : null,
            ],
        ];
    }

    public function getRamInfo(): array
    {
        $raw = $this->exec('cat /proc/meminfo');

        if (! $raw) {
            return $this->ramFallback();
        }

        preg_match('/MemTotal:\s+(\d+)\s+kB/i',     $raw, $total);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $raw, $available);
        preg_match('/MemFree:\s+(\d+)\s+kB/i',      $raw, $free);
        preg_match('/Cached:\s+(\d+)\s+kB/i',       $raw, $cached);
        preg_match('/Buffers:\s+(\d+)\s+kB/i',      $raw, $buffers);

        $totalBytes    = isset($total[1])     ? (int) $total[1] * 1024     : 0;
        $availBytes    = isset($available[1]) ? (int) $available[1] * 1024 : 0;
        $usedBytes     = $totalBytes - $availBytes;

        return [
            'total'     => $this->formatBytes($totalBytes),
            'used'      => $this->formatBytes($usedBytes),
            'free'      => $this->formatBytes($availBytes),
            'cached'    => isset($cached[1])   ? $this->formatBytes((int) $cached[1] * 1024)   : null,
            'buffers'   => isset($buffers[1])  ? $this->formatBytes((int) $buffers[1] * 1024)  : null,
            'usage_pct' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : null,
            'total_raw' => $totalBytes,
            'used_raw'  => $usedBytes,
        ];
    }

    public function getDiskInfo(): array
    {
        $raw = $this->exec("df -BK --output=source,fstype,size,used,avail,pcent,target 2>/dev/null | tail -n +2");

        if (! $raw) {
            return $this->diskFallback();
        }

        $disks = [];
        foreach (explode("\n", $raw) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 7) {
                continue;
            }

            [$source, $fstype, $size, $used, $avail, $pct, $mount] = $parts;

            // Skip pseudo filesystems
            if (in_array($fstype, ['tmpfs', 'devtmpfs', 'squashfs', 'overlay', 'proc', 'sysfs', 'devpts'], true)) {
                continue;
            }

            $disks[] = [
                'device'     => $source,
                'filesystem' => $fstype,
                'mount'      => $mount,
                'total'      => $this->kbToHuman($size),
                'used'       => $this->kbToHuman($used),
                'free'       => $this->kbToHuman($avail),
                'usage_pct'  => rtrim($pct, '%'),
            ];
        }

        return $disks;
    }

    public function getWebServerInfo(): array
    {
        $nginx  = $this->exec('nginx -v 2>&1');
        $apache = $this->exec('apache2 -v 2>&1 || httpd -v 2>&1');

        return [
            'nginx_version'  => $nginx  ? trim(str_replace('nginx version: ', '', $nginx))  : null,
            'apache_version' => $apache ? trim(explode("\n", $apache)[0] ?? '') : null,
        ];
    }

    private function getCpuUsagePercent(): ?float
    {
        $stat1 = @file_get_contents('/proc/stat');
        if (! $stat1) {
            return null;
        }

        usleep(200_000); // 200ms sample

        $stat2 = @file_get_contents('/proc/stat');
        if (! $stat2) {
            return null;
        }

        $parse = static function (string $stat): array {
            preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $stat, $m);
            $idle  = (int) ($m[4] ?? 0) + (int) ($m[5] ?? 0);
            $total = array_sum(array_slice(array_map('intval', $m), 1));

            return ['idle' => $idle, 'total' => $total];
        };

        $s1 = $parse($stat1);
        $s2 = $parse($stat2);

        $diffIdle  = $s2['idle']  - $s1['idle'];
        $diffTotal = $s2['total'] - $s1['total'];

        if ($diffTotal === 0) {
            return null;
        }

        return round((1 - $diffIdle / $diffTotal) * 100, 1);
    }

    private function kbToHuman(string $kb): string
    {
        return $this->formatBytes((int) rtrim($kb, 'K') * 1024);
    }

    private function ramFallback(): array
    {
        return [
            'total'     => null,
            'used'      => null,
            'free'      => null,
            'cached'    => null,
            'buffers'   => null,
            'usage_pct' => null,
            'note'      => '/proc/meminfo not accessible',
        ];
    }

    private function diskFallback(): array
    {
        $total = disk_total_space('/');
        $free  = disk_free_space('/');

        if ($total === false) {
            return [];
        }

        return [[
            'device'     => '/',
            'filesystem' => 'unknown',
            'mount'      => '/',
            'total'      => $this->formatBytes($total),
            'used'       => $this->formatBytes($total - $free),
            'free'       => $this->formatBytes($free),
            'usage_pct'  => round((($total - $free) / $total) * 100, 1),
        ]];
    }
}
