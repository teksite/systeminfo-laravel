<?php

namespace Teksite\SystemInfo\Drivers;

class MacOsInfoDriver extends AbstractSystemInfoDriver
{
    public function getOsInfo(): array
    {
        $version = $this->exec('sw_vers -productVersion');
        $name = $this->exec('sw_vers -productName');
        $kernel = $this->exec('uname -r');
        $machine = $this->exec('uname -m');
        $uptime = $this->exec('uptime | awk -F"up " \'{print $2}\' | awk -F"," \'{print $1}\'');

        return [
            'platform'     => 'macOS',
            'distribution' => trim(($name ?? 'macOS') . ' ' . ($version ?? '')),
            'kernel'       => $kernel ?? php_uname('r'),
            'architecture' => $machine ?? php_uname('m'),
            'hostname'     => gethostname() ?: 'unknown',
            'uptime'       => $uptime ?? null,
        ];
    }

    public function getCpuInfo(): array
    {
        $model = $this->exec('sysctl -n machdep.cpu.brand_string');
        $cores = $this->exec('sysctl -n hw.logicalcpu');
        $usage = $this->getCpuUsagePercent();

        return [
            'model'     => $model ?? 'unknown',
            'cores'     => $cores ? (int)$cores : null,
            'usage_pct' => $usage,
            'load_avg'  => $this->getLoadAvg(),
        ];
    }

    public function getRamInfo(): array
    {
        $totalRaw = $this->exec('sysctl -n hw.memsize');

        if (!$totalRaw) {
            return $this->ramFallback();
        }

        $totalBytes = (int)$totalRaw;

        // vm_stat for free/active/inactive pages
        $vmStat = $this->exec('vm_stat');
        $free = $active = $inactive = $wired = 0;

        if ($vmStat) {
            preg_match('/page size of (\d+) bytes/', $vmStat, $pageMatch);
            $pageSize = isset($pageMatch[1]) ? (int)$pageMatch[1] : 4096;

            $parsePages = static function (string $key) use ($vmStat): int {
                preg_match("/{$key}:\s+(\d+)/", $vmStat, $m);

                return isset($m[1]) ? (int)$m[1] : 0;
            };

            $free = $parsePages('Pages free') * $pageSize;
            $active = $parsePages('Pages active') * $pageSize;
            $inactive = $parsePages('Pages inactive') * $pageSize;
            $wired = $parsePages('Pages wired down') * $pageSize;
        }

        $usedBytes = $active + $wired;

        return [
            'total'     => $this->formatBytes($totalBytes),
            'used'      => $this->formatBytes($usedBytes),
            'free'      => $this->formatBytes($free),
            'active'    => $this->formatBytes($active),
            'inactive'  => $this->formatBytes($inactive),
            'wired'     => $this->formatBytes($wired),
            'cached'    => null,
            'buffers'   => null,
            'usage_pct' => $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : null,
            'total_raw' => $totalBytes,
            'used_raw'  => $usedBytes,
        ];
    }

    public function getDiskInfo(): array
    {
        $raw = $this->exec('df -k');

        if (!$raw) {
            return $this->diskFallback();
        }

        $disks = [];

        foreach (explode("\n", $raw) as $i => $line) {
            if ($i === 0 || empty(trim($line))) {
                continue;
            }

            $parts = preg_split('/\s+/', trim($line), 9);
            if (count($parts) < 9) {
                continue;
            }

            [$filesystem, $blocks, $used, $avail, $pct, , , , $mount] = $parts;

            // Skip pseudo filesystems
            if (str_starts_with($filesystem, 'devfs') || str_starts_with($mount, '/dev')) {
                continue;
            }

            $totalBytes = (int)$blocks * 1024;
            $usedBytes = (int)$used * 1024;
            $freeBytes = (int)$avail * 1024;

            $disks[] = [
                'device'     => $filesystem,
                'filesystem' => null,
                'mount'      => $mount,
                'total'      => $this->formatBytes($totalBytes),
                'used'       => $this->formatBytes($usedBytes),
                'free'       => $this->formatBytes($freeBytes),
                'usage_pct'  => rtrim($pct, '%'),
            ];
        }

        return $disks ?: $this->diskFallback();
    }

    public function getWebServerInfo(): array
    {
        $nginx = $this->exec('nginx -v 2>&1');
        $apache = $this->exec('apachectl -v 2>&1');

        return [
            'nginx_version'  => $nginx ? trim(str_replace('nginx version: ', '', $nginx)) : null,
            'apache_version' => $apache ? trim(explode("\n", $apache)[0] ?? '') : null,
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function getCpuUsagePercent(): ?float
    {
        $raw = $this->exec("top -l 1 -n 0 | grep 'CPU usage'");

        if (!$raw) {
            return null;
        }

        preg_match('/(\d+\.\d+)%\s+user/', $raw, $user);
        preg_match('/(\d+\.\d+)%\s+sys/', $raw, $sys);

        $u = isset($user[1]) ? (float)$user[1] : 0.0;
        $s = isset($sys[1]) ? (float)$sys[1] : 0.0;

        return round($u + $s, 1);
    }

    private function getLoadAvg(): array
    {
        $raw = $this->exec('sysctl -n vm.loadavg');

        if (!$raw) {
            return ['1min' => null, '5min' => null, '15min' => null];
        }

        // Format: { 0.12 0.34 0.56 }
        $raw = trim($raw, " {}");
        $values = array_values(array_filter(explode(' ', $raw)));

        return [
            '1min'  => isset($values[0]) ? (float)$values[0] : null,
            '5min'  => isset($values[1]) ? (float)$values[1] : null,
            '15min' => isset($values[2]) ? (float)$values[2] : null,
        ];
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
            'note'      => 'sysctl not accessible',
        ];
    }

    private function diskFallback(): array
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');

        if ($total === false) {
            return [];
        }

        return [[
            'device'     => '/',
            'filesystem' => 'APFS',
            'mount'      => '/',
            'total'      => $this->formatBytes($total),
            'used'       => $this->formatBytes($total - $free),
            'free'       => $this->formatBytes($free),
            'usage_pct'  => round((($total - $free) / $total) * 100, 1),
        ]];
    }
}
