<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Concerns\CalculatesPercent;
use Teksite\SystemInfo\Support\SafeExecutor;
use Teksite\SystemInfo\Support\SystemDetector;

class LinuxDriver implements DriverInterface
{
    use CalculatesPercent;

    public function cpu(): array
    {
        return [
            'cores' => (int) (SafeExecutor::exec('nproc') ?? 0),
            'load_average' => sys_getloadavg(),
            'available' => SystemDetector::hasNproc(),
        ];
    }

    public function ram(): array
    {
        if (!SystemDetector::hasProc()) {
            return ['error' => 'proc not accessible'];
        }

        $data = $this->readMem();

        $total = $data['MemTotal'] ?? 0;
        $free = $data['MemAvailable'] ?? $data['MemFree'] ?? 0;

        return [
            'total_kb' => $total,
            'free_kb' => $free,
            'used_kb' => $total - $free,
            'usage_percent' => $this->percent($total - $free, $total),
        ];
    }

    private function readMem(): array
    {
        if (!is_readable('/proc/meminfo')) {
            return [];
        }

        $lines = file('/proc/meminfo');

        $data = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;

            [$k, $v] = explode(':', $line);
            $data[$k] = (int) filter_var($v, FILTER_SANITIZE_NUMBER_INT);
        }

        return $data;
    }

    public function disk(): array
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');

        if (!$total) {
            return ['error' => 'disk not available'];
        }

        return [
            'total_bytes' => $total,
            'free_bytes' => $free,
            'used_bytes' => $total - $free,
            'usage_percent' => $this->percent($total - $free, $total),
        ];
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
            'os' => 'linux',
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
            'proc' => SystemDetector::hasProc(),
            'nproc' => SystemDetector::hasNproc(),
            'nvidia' => SystemDetector::hasNvidia(),
        ];
    }
}
