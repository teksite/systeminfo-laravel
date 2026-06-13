<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Concerns\DriverCal;
use Teksite\SystemInfo\Contracts\DriverInterface;

class LinuxDriver implements DriverInterface
{

    use DriverCal;

    /**
     * CPU INFO
     */
    public function cpu(): array
    {
        return [
            'cores'        => $this->getCpuCores(),
            'load_average' => sys_getloadavg(), // [1min, 5min, 15min]
        ];
    }

    protected function getCpuCores(): int
    {
        return (int)trim(shell_exec('nproc'));
    }

    /**
     * RAM INFO
     */
    public function ram(): array
    {
        $memInfo = $this->readProcMemInfo();

        $total = $memInfo['MemTotal'] ?? 0;
        $available = $memInfo['MemAvailable'] ?? $memInfo['MemFree'] ?? 0;

        $used = $total - $available;

        return [
            'total_kb'      => $total,
            'used_kb'       => $used,
            'free_kb'       => $available,
            'usage_percent' => $this->percent($used, $total),
        ];
    }

    protected function readProcMemInfo(): array
    {
        $lines = @file('/proc/meminfo');

        if (!$lines) {
            return [];
        }

        $data = [];

        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            [$key, $value] = explode(':', $line);

            $data[$key] = (int)filter_var($value, FILTER_SANITIZE_NUMBER_INT);
        }

        return $data;
    }

    /**
     * DISK INFO
     */
    public function disk(string $path = '/'): array
    {
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'path'          => $path,
            'total_bytes'   => $total,
            'used_bytes'    => $used,
            'free_bytes'    => $free,
            'usage_percent' => $this->percent($used, $total),
        ];
    }

    /**
     * GPU INFO (NVIDIA only)
     */
    public function gpu(): ?array
    {
        $command = 'nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu --format=csv,noheader,nounits';

        $output = shell_exec($command);

        if (!$output) {
            return null;
        }

        $rows = array_filter(array_map('trim', explode("\n", trim($output))));

        $gpus = [];

        foreach ($rows as $row) {
            $parts = array_map('trim', explode(',', $row));

            $gpus[] = [
                'name'            => $parts[0] ?? null,
                'memory_total_mb' => isset($parts[1]) ? (int)$parts[1] : null,
                'memory_used_mb'  => isset($parts[2]) ? (int)$parts[2] : null,
                'memory_free_mb'  => isset($parts[3]) ? (int)$parts[3] : null,
                'usage_percent'   => isset($parts[4]) ? (int)$parts[4] : null,
            ];
        }

        return $gpus;
    }

    /**
     * FULL SNAPSHOT
     */
    public function snapshot(): array
    {
        return [
            'cpu'       => $this->cpu(),
            'ram'       => $this->ram(),
            'disk'      => $this->disk(),
            'gpu'       => $this->gpu(),
            'timestamp' => now()->toDateTimeString(),
        ];
    }

}
