<?php

namespace Teksite\SystemInfo\Drivers;

class WindowsInfoDriver extends AbstractSystemInfoDriver
{
    public function getOsInfo(): array
    {
        $version = $this->execWin('ver');
        $uptime = $this->getWindowsUptime();

        return [
            'platform'     => 'Windows',
            'distribution' => $version ?? php_uname('s'),
            'kernel'       => php_uname('r'),
            'architecture' => php_uname('m'),
            'hostname'     => gethostname() ?: 'unknown',
            'uptime'       => $uptime,
        ];
    }

    public function getCpuInfo(): array
    {
        $model = $this->execWin(
            'wmic cpu get name /value'
        );

        $cores = $this->execWin(
            'wmic cpu get NumberOfLogicalProcessors /value'
        );

        $usage = $this->execWin(
            'wmic cpu get loadpercentage /value'
        );

        return [
            'model'     => $model ? $this->parseWmicValue($model) : 'unknown',
            'cores'     => $cores ? (int)$this->parseWmicValue($cores) : null,
            'usage_pct' => $usage ? (float)$this->parseWmicValue($usage) : null,
            'load_avg'  => null, // Not available natively on Windows
        ];
    }

    public function getRamInfo(): array
    {
        $totalRaw = $this->execWin('wmic OS get TotalVisibleMemorySize /value');
        $freeRaw = $this->execWin('wmic OS get FreePhysicalMemory /value');

        if (!$totalRaw || !$freeRaw) {
            return $this->ramFallback();
        }

        $totalKb = (int)$this->parseWmicValue($totalRaw);
        $freeKb = (int)$this->parseWmicValue($freeRaw);
        $usedKb = $totalKb - $freeKb;

        $totalBytes = $totalKb * 1024;
        $freeBytes = $freeKb * 1024;
        $usedBytes = $usedKb * 1024;

        return [
            'total'     => $this->formatBytes($totalBytes),
            'used'      => $this->formatBytes($usedBytes),
            'free'      => $this->formatBytes($freeBytes),
            'cached'    => null,
            'buffers'   => null,
            'usage_pct' => $totalKb > 0 ? round(($usedKb / $totalKb) * 100, 1) : null,
            'total_raw' => $totalBytes,
            'used_raw'  => $usedBytes,
        ];
    }

    public function getDiskInfo(): array
    {
        $raw = $this->execWin(
            'wmic logicaldisk get DeviceID,FileSystem,Size,FreeSpace /format:csv'
        );

        if (!$raw) {
            return $this->diskFallback();
        }

        $disks = [];
        $lines = array_filter(explode("\n", trim($raw)));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with(strtolower($line), 'node')) {
                continue;
            }

            $parts = explode(',', $line);
            if (count($parts) < 5) {
                continue;
            }

            // CSV columns: Node, DeviceID, FileSystem, FreeSpace, Size
            [, $device, $filesystem, $freeSpace, $size] = $parts;

            $totalBytes = (int)trim($size);
            $freeBytes = (int)trim($freeSpace);
            $usedBytes = $totalBytes - $freeBytes;

            if ($totalBytes === 0) {
                continue;
            }

            $disks[] = [
                'device'     => trim($device),
                'filesystem' => trim($filesystem) ?: 'unknown',
                'mount'      => trim($device),
                'total'      => $this->formatBytes($totalBytes),
                'used'       => $this->formatBytes($usedBytes),
                'free'       => $this->formatBytes($freeBytes),
                'usage_pct'  => round(($usedBytes / $totalBytes) * 100, 1),
            ];
        }

        return $disks ?: $this->diskFallback();
    }

    public function getWebServerInfo(): array
    {
        $iis = $this->execWin('wmic service where "name=\'W3SVC\'" get displayname,state /value');
        $nginx = $this->execWin('nginx -v 2>&1');

        return [
            'nginx_version'  => $nginx ? trim($nginx) : null,
            'iis_status'     => $iis ? $this->parseWmicValue($iis) : null,
            'apache_version' => null, // Rarely used on Windows natively
        ];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function parseWmicValue(string $output): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '=')) {
                [, $value] = explode('=', $line, 2);
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function getWindowsUptime(): ?string
    {
        $raw = $this->execWin(
            'wmic os get LastBootUpTime /value'
        );

        if (!$raw) {
            return null;
        }

        $bootTime = $this->parseWmicValue($raw);

        if (!$bootTime) {
            return null;
        }

        try {
            // Format: YYYYMMDDHHmmss.xxxxxx+ZZZ
            $dt = \DateTime::createFromFormat('YmdHis.u', substr($bootTime, 0, 21));
            $diff = (new \DateTime())->diff($dt);

            return sprintf('%dd %dh %dm', $diff->days, $diff->h, $diff->i);
        } catch (\Throwable) {
            return null;
        }
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
            'note'      => 'wmic not accessible',
        ];
    }

    private function diskFallback(): array
    {
        $drives = range('C', 'Z');
        $disks = [];

        foreach ($drives as $drive) {
            $path = $drive . ':\\';
            if (!is_dir($path)) {
                continue;
            }

            $total = @disk_total_space($path);
            $free = @disk_free_space($path);

            if ($total === false || $total === 0) {
                continue;
            }

            $disks[] = [
                'device'     => $path,
                'filesystem' => 'unknown',
                'mount'      => $path,
                'total'      => $this->formatBytes($total),
                'used'       => $this->formatBytes($total - $free),
                'free'       => $this->formatBytes($free),
                'usage_pct'  => round((($total - $free) / $total) * 100, 1),
            ];
        }

        return $disks;
    }
}
