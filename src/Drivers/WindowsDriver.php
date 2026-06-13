<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Concerns\CalculatesPercent;
use Teksite\SystemInfo\Support\SafeExecutor;
use Teksite\SystemInfo\Support\SystemDetector;

class WindowsDriver implements DriverInterface
{
    use CalculatesPercent;

    public function cpu(): array
    {
        $cores = SafeExecutor::exec('wmic cpu get NumberOfCores');

        preg_match_all('/\d+/', $cores ?? '', $m);

        return [
            'cores' => (int) ($m[0][0] ?? 0),
            'usage_percent' => $this->cpuUsage(),
        ];
    }

    private function cpuUsage(): float
    {
        $cmd = 'powershell -command "Get-Counter \'\\Processor(_Total)\\% Processor Time\' | Select -ExpandProperty CounterSamples | Select -ExpandProperty CookedValue"';

        return (float) SafeExecutor::exec($cmd);
    }

    public function ram(): array
    {
        $out = SafeExecutor::exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');

        preg_match('/TotalVisibleMemorySize=(\d+)/', $out ?? '', $t);
        preg_match('/FreePhysicalMemory=(\d+)/', $out ?? '', $f);

        $total = (int) ($t[1] ?? 0);
        $free = (int) ($f[1] ?? 0);

        return [
            'total_kb' => $total,
            'free_kb' => $free,
            'used_kb' => $total - $free,
            'usage_percent' => $this->percent($total - $free, $total),
        ];
    }

    public function disk(): array
    {
        $out = SafeExecutor::exec('wmic logicaldisk get size,freespace,caption');

        if (!$out) return [];

        $lines = explode("\n", $out);

        $disks = [];

        foreach ($lines as $line) {
            if (!preg_match('/([A-Z]):\s+(\d+)\s+(\d+)/', $line, $m)) {
                continue;
            }

            $free = (int) $m[2];
            $size = (int) $m[3];

            $disks[] = [
                'disk' => $m[1],
                'total_bytes' => $size,
                'free_bytes' => $free,
                'used_bytes' => $size - $free,
                'usage_percent' => $this->percent($size - $free, $size),
            ];
        }

        return $disks;
    }

    public function gpu(): ?array
    {
        if (!SystemDetector::hasNvidia()) {
            return null;
        }

        $out = SafeExecutor::exec(
            'nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu --format=csv,noheader,nounits'
        );

        if (!$out) return null;

        return collect(explode("\n", $out))
            ->filter()
            ->map(function ($row) {
                $p = array_map('trim', explode(',', $row));

                return [
                    'name' => $p[0] ?? null,
                    'memory_total_mb' => (int) ($p[1] ?? 0),
                    'memory_used_mb' => (int) ($p[2] ?? 0),
                    'memory_free_mb' => (int) ($p[3] ?? 0),
                    'usage_percent' => (int) ($p[4] ?? 0),
                ];
            })
            ->values()
            ->toArray();
    }

    public function snapshot(): array
    {
        return [
            'os' => 'windows',
            'cpu' => $this->cpu(),
            'ram' => $this->ram(),
            'disk' => $this->disk(),
            'gpu' => $this->gpu(),
            'timestamp' => now(),
        ];
    }

    public function capabilities(): array
    {
        return [
            'wmic' => SystemDetector::hasWmic(),
            'nvidia' => SystemDetector::hasNvidia(),
        ];
    }
}
