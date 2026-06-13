<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Concerns\DriverCal;
use Teksite\SystemInfo\Contracts\DriverInterface;

class WindowsDriver implements DriverInterface
{
    use DriverCal;

    /**
     * =========================
     * CPU
     * =========================
     */
    public function cpu(): array
    {
        return [
            'cores'         => $this->getCpuCores(),
            'usage_percent' => $this->getCpuUsage(),
        ];
    }

    protected function getCpuCores(): int
    {
        $output = shell_exec('wmic cpu get NumberOfCores');

        preg_match_all('/\d+/', $output, $matches);

        return isset($matches[0][0]) ? (int)$matches[0][0] : 0;
    }

    protected function getCpuUsage(): float
    {
        // PowerShell counter (instant CPU load)
        $cmd = 'powershell -command "Get-Counter \'\Processor(_Total)\% Processor Time\' | Select -ExpandProperty CounterSamples | Select -ExpandProperty CookedValue"';

        $output = shell_exec($cmd);

        return round((float)trim($output), 2);
    }

    /**
     * =========================
     * RAM
     * =========================
     */
    public function ram(): array
    {
        $output = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');

        preg_match('/TotalVisibleMemorySize=(\d+)/', $output, $total);
        preg_match('/FreePhysicalMemory=(\d+)/', $output, $free);

        $totalKb = isset($total[1]) ? (int)$total[1] : 0;
        $freeKb = isset($free[1]) ? (int)$free[1] : 0;

        $usedKb = $totalKb - $freeKb;

        return [
            'total_kb'      => $totalKb,
            'free_kb'       => $freeKb,
            'used_kb'       => $usedKb,
            'usage_percent' => $this->percent($usedKb, $totalKb),
        ];
    }

    /**
     * =========================
     * DISK
     * =========================
     */
    public function disk(): array
    {
        $cmd = 'wmic logicaldisk get size,freespace,caption';

        $output = shell_exec($cmd);

        $lines = array_filter(array_map('trim', explode("\n", trim($output))));

        $disks = [];

        foreach ($lines as $line) {
            if (!preg_match('/^([A-Z]):\s+(\d+)\s+(\d+)$/', $line, $m)) {
                continue;
            }

            $disk = $m[1];
            $free = (int)$m[2];
            $size = (int)$m[3];

            $used = $size - $free;

            $disks[] = [
                'disk'          => $disk,
                'total_bytes'   => $size,
                'free_bytes'    => $free,
                'used_bytes'    => $used,
                'usage_percent' => $this->percent($used, $size),
            ];
        }

        return $disks;
    }

    /**
     * =========================
     * GPU (NVIDIA ONLY)
     * =========================
     */
    public function gpu(): ?array
    {
        $cmd = 'nvidia-smi --query-gpu=name,memory.total,memory.used,memory.free,utilization.gpu --format=csv,noheader,nounits';

        $output = shell_exec($cmd);

        if (!$output) {
            return null;
        }

        $lines = array_filter(array_map('trim', explode("\n", trim($output))));

        $gpus = [];

        foreach ($lines as $line) {
            $p = array_map('trim', explode(',', $line));

            $gpus[] = [
                'name'            => $p[0] ?? null,
                'memory_total_mb' => isset($p[1]) ? (int)$p[1] : null,
                'memory_used_mb'  => isset($p[2]) ? (int)$p[2] : null,
                'memory_free_mb'  => isset($p[3]) ? (int)$p[3] : null,
                'usage_percent'   => isset($p[4]) ? (int)$p[4] : null,
            ];
        }

        return $gpus;
    }

    /**
     * =========================
     * FULL SNAPSHOT
     * =========================
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
