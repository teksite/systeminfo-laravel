<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Concerns\WindowsPS;
use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Support\SafeExecutor;

class WindowsHardware
{
    use WindowsPS;

    public function cpu(): array
    {

        $data = $this->ps('Get-CimInstance Win32_Processor | Select Name,NumberOfCores,NumberOfLogicalProcessors,MaxClockSpeed,CurrentClockSpeed,LoadPercentage | ConvertTo-Json');

        $cpu = json_decode($data ?? '', true);

        return !is_array($cpu)
            ? [
                'model'             => null,
                'cores'             => 0,
                'threads'           => 0,
                'max_clock_mhz'     => 0,
                'current_clock_mhz' => 0,
                'usage_percent'     => 0,
                'architecture'      => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
            ]
            : [
                'model'             => $cpu['Name'] ?? null,
                'cores'             => (int)($cpu['NumberOfCores'] ?? 0),
                'threads'           => (int)($cpu['NumberOfLogicalProcessors'] ?? 0),
                'max_clock_mhz'     => (int)($cpu['MaxClockSpeed'] ?? 0),
                'current_clock_mhz' => (int)($cpu['CurrentClockSpeed'] ?? 0),
                'usage_percent'     => (float)($cpu['LoadPercentage'] ?? 0),
                'architecture'      => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
            ];
    }

    public function ram(): array
    {

        $sys = json_decode($this->ps('Get-CimInstance Win32_ComputerSystem | Select TotalPhysicalMemory | ConvertTo-Json'), true);
        $os = json_decode($this->ps('Get-CimInstance Win32_OperatingSystem | Select FreePhysicalMemory,TotalVisibleMemorySize | ConvertTo-Json'), true);

        $total = (int)($sys['TotalPhysicalMemory'] ?? 0);
        $freeKb = (int)($os['FreePhysicalMemory'] ?? 0);

        $free = $freeKb * 1024;
        $used = $total - $free;

        if ($total <= 0) {
            return [
                'total'   => null,
                'used'    => null,
                'free'    => null,
                'percent' => null,
            ];
        }


        return [
            'total'   => $total,
            'used'    => $used,
            'free'    => $free,
            'percent' => $total ? round(($used / $total) * 100, 2) : 0,
        ];
    }

    public function disk(): array
    {
        $json = $this->ps(
            'Get-CimInstance Win32_LogicalDisk | Select DeviceID,Size,FreeSpace | ConvertTo-Json'
        );

        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data) || isset($data['DeviceID'])) {
            $data = [$data];
        }
        $result = [];

        foreach ($data as $d) {
            $total = (int)($d['Size'] ?? 0);
            $free = (int)($d['FreeSpace'] ?? 0);
            $used = $total - $free;
            $result[] = [
                'path'    => $d['DeviceID'] ?? null,
                'total'   => $total,
                'used'    => $used,
                'free'    => $free,
                'percent' => $total ? round(($used / $total) * 100, 2) : 0,
            ];
        }

        return $result;
    }

    public function gpu(): array
    {
        $gpuRaw = $this->ps(
            'Get-CimInstance Win32_VideoController | Select Name,AdapterRAM,DriverVersion,CurrentRefreshRate | ConvertTo-Json -Depth 2'
        );

        $gpu = json_decode($gpuRaw ?? '', true);

        if (!$gpu) {
            return [
                'name'           => null,
                'memory'         => null,
                'driver_version' => null,
                'refresh_rate'   => null,
            ];
        }

        if (isset($gpu[0])) {
            $gpu = $gpu[0];
        }

        return [
            'name'           => $gpu['Name'] ?? null,
            'memory'         => $gpu['AdapterRAM'] ?? null,
            'driver_version' => $gpu['DriverVersion'] ?? null,
            'refresh_rate'   => $gpu['CurrentRefreshRate'] ?? null,
        ];
    }

}
