<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxHardware
{
    public function cpu(): array
    {
        $info = SafeExecutor::fileExists('/proc/cpuinfo') ? file_get_contents('/proc/cpuinfo') : null;

        if (!$info) return [
            'model'   => null,
            'cores'   => 0,
            'threads' => 0,
            'load'    => sys_getloadavg(),
        ];

        preg_match('/model name\s+:\s+(.+)/', $info, $model);
        preg_match('/cpu cores\s+:\s+(\d+)/', $info, $cores);
        preg_match('/siblings\s+:\s+(\d+)/', $info, $threads);

        return [
            'model'   => $model[1] ?? null,
            'cores'   => (int)($cores[1] ?? 0),
            'threads' => (int)($threads[1] ?? 0),
            'load'    => sys_getloadavg(),
        ];
    }

    public function ram(): array
    {
        $data = file_get_contents('/proc/meminfo');


        preg_match('/MemTotal:\s+(\d+)/', $data, $t);
        preg_match('/MemAvailable:\s+(\d+)/', $data, $a);

        $total = (int)($t[1] ?? 0);
        $avail = (int)($a[1] ?? 0);

        if ($total <= 0) {
            return [
                'total'   => null,
                'used'    => null,
                'free'    => null,
                'percent' => null,
            ];
        }

        $used = $total - $avail;

        return [
            'total'   => $total * 1024,
            'used'    => $used * 1024,
            'free'    => $avail * 1024,
            'percent' => $total ? round(($used / $total) * 100, 2) : 0,
        ];
    }

    public function disk(): array
    {
        $path = '/';

        return [
            'path'    => $path,
            'total'   => disk_total_space($path),
            'used'    => disk_total_space($path) - disk_free_space($path),
            'free'    => disk_free_space($path),
            'percent' => round(
                ((disk_total_space($path) - disk_free_space($path)) / disk_total_space($path)) * 100,
                2
            ),
        ];
    }

    public function gpu(): array
    {
        if (!SafeExecutor::commandExists('lspci')) {
            return [
                'name'           => null,
                'memory'         => null,
                'driver_version' => null,
                'refresh_rate'   => null,
            ];
        }

        $out = SafeExecutor::exec('lspci | grep -i vga');

        return $out
            ? [
                'info' => $out,
            ]
            : [
                'name'           => null,
                'memory'         => null,
                'driver_version' => null,
                'refresh_rate'   => null,
            ];
    }
}
