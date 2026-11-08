<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Support\Glob;
use Teksite\SystemInfo\Support\Options;
use Teksite\SystemInfo\Support\PowerShell;

/**
 * Windows driver.
 *
 * Performance model: spawning powershell.exe costs 300-800 ms, so the whole driver is built
 * around doing it as rarely as possible:
 *   - the static inventory is ONE call (and is cached across requests by the manager);
 *   - a live sample is ONE call that returns CPU, memory, swap, disks, network and disk I/O.
 * The previous implementation spawned PowerShell ~10 times per collector() call (plus a
 * `where powershell` lookup before each one).
 */
class WindowsDriver extends AbstractDriver
{
    private const string INVENTORY_SCRIPT = <<<'PS'
                             $os = Get-CimInstance Win32_OperatingSystem
                             $cs = Get-CimInstance Win32_ComputerSystem
                             $cpu = @(Get-CimInstance Win32_Processor)
                             $gpu = @(Get-CimInstance Win32_VideoController)
                             [pscustomobject]@{
                               cpu = @($cpu | ForEach-Object { [pscustomobject]@{ name = "$($_.Name)".Trim(); cores = $_.NumberOfCores; threads = $_.NumberOfLogicalProcessors; max_clock = $_.MaxClockSpeed } })
                               gpu = @($gpu | ForEach-Object { [pscustomobject]@{ name = $_.Name; memory = $_.AdapterRAM; driver = $_.DriverVersion } })
                               os = [pscustomobject]@{ caption = $os.Caption; version = $os.Version; build = $os.BuildNumber; arch = $os.OSArchitecture; product_type = $os.ProductType; boot = ([DateTimeOffset]$os.LastBootUpTime).ToUnixTimeSeconds() }
                               timezone = [TimeZoneInfo]::Local.Id
                             } | ConvertTo-Json -Depth 5 -Compress
                             PS;

    private const string SAMPLE_SCRIPT = <<<'PS'
                             $os = Get-CimInstance Win32_OperatingSystem
                             $cpu = @(Get-CimInstance Win32_Processor)
                             $pf = @(Get-CimInstance Win32_PageFileUsage)
                             $disks = @(Get-CimInstance Win32_LogicalDisk -Filter "DriveType=3")
                             $net = @(Get-CimInstance Win32_PerfRawData_Tcpip_NetworkInterface)
                             $dio = Get-CimInstance Win32_PerfRawData_PerfDisk_PhysicalDisk -Filter "Name='_Total'"
                             [pscustomobject]@{
                               cpu = ($cpu | Measure-Object -Property LoadPercentage -Average).Average
                               mem_total = $os.TotalVisibleMemorySize
                               mem_free = $os.FreePhysicalMemory
                               swap_total = ($pf | Measure-Object -Property AllocatedBaseSize -Sum).Sum
                               swap_used = ($pf | Measure-Object -Property CurrentUsage -Sum).Sum
                               boot = ([DateTimeOffset]$os.LastBootUpTime).ToUnixTimeSeconds()
                               disks = @($disks | ForEach-Object { [pscustomobject]@{ id = $_.DeviceID; size = $_.Size; free = $_.FreeSpace; fs = $_.FileSystem } })
                               net = @($net | ForEach-Object { [pscustomobject]@{ name = $_.Name; rx = $_.BytesReceivedPersec; tx = $_.BytesSentPersec } })
                               io_read = $dio.DiskReadBytesPersec
                               io_write = $dio.DiskWriteBytesPersec
                             } | ConvertTo-Json -Depth 5 -Compress
                             PS;

    private readonly PowerShell $powershell;

    public function __construct(Options|array|null $options = null, ?PowerShell $powershell = null)
    {
        parent::__construct($options);

        $this->powershell = $powershell ?? new PowerShell((float)$this->options->get('shell.powershell_timeout', 15.0));
    }

    public function family(): string
    {
        return 'Windows';
    }

    /** Windows has no load average. */
    protected function currentLoad(): ?array
    {
        return null;
    }

    // ---- live sample -------------------------------------------------------------------------

    public function sample(): array
    {
        $started = microtime(true);
        $data = $this->powershell->json(self::SAMPLE_SCRIPT) ?? [];
        // The counters were read somewhere inside the call: the midpoint is the least biased timestamp.
        $time = ($started + microtime(true)) / 2;

        $totalKb = self::int($data['mem_total'] ?? null);
        $freeKb = self::int($data['mem_free'] ?? null);

        $memory = $totalKb > 0 ? [
            'total'      => $totalKb * 1024,
            'available'  => $freeKb * 1024,
            'cached'     => null,
            'swap_total' => self::int($data['swap_total'] ?? null) * 1048576,
            'swap_used'  => self::int($data['swap_used'] ?? null) * 1048576,
        ] : null;

        $exclude = (array)$this->options->get('network.exclude_interfaces', []);
        $rx = $tx = 0;

        foreach (self::list($data['net'] ?? []) as $nic) {
            if (!Glob::matchAny($exclude, (string)($nic['name'] ?? ''))) {
                $rx += self::int($nic['rx'] ?? null);
                $tx += self::int($nic['tx'] ?? null);
            }
        }

        $disks = [];

        foreach (self::list($data['disks'] ?? []) as $disk) {
            $size = (float)($disk['size'] ?? 0);

            if ($size <= 0 || !isset($disk['id'])) continue;

            $free = (float)($disk['free'] ?? 0);
            $used = max(0.0, $size - $free);

            $disks[] = [
                'path'    => (string)$disk['id'],
                'device'  => (string)$disk['id'],
                'fs'      => $disk['fs'] ?? null,
                'total'   => (int)$size,
                'used'    => (int)$used,
                'free'    => (int)$free,
                'percent' => round(min(100.0, $used / $size * 100), 2),
            ];
        }

        $boot = self::int($data['boot'] ?? null);

        return [
            'time'      => $time,
            // Win32_Processor.LoadPercentage is already an OS-side average: no delta needed.
            'cpu'       => ['usage' => isset($data['cpu']) && is_numeric($data['cpu']) ? (float)$data['cpu'] : null],
            'load'      => null,
            'memory'    => $memory,
            'network'   => $data === [] ? null : ['rx' => $rx, 'tx' => $tx],
            'disk_io'   => $data === [] ? null : ['read' => self::int($data['io_read'] ?? null), 'write' => self::int($data['io_write'] ?? null)],
            'disks'     => $disks,
            'container' => null,
            'uptime'    => $boot > 0 ? max(0, time() - $boot) : null,
        ];
    }

    // ---- static inventory --------------------------------------------------------------------

    protected function buildBase(): array
    {
        $data = $this->powershell->json(self::INVENTORY_SCRIPT) ?? [];
        $os = is_array($data['os'] ?? null) ? $data['os'] : [];

        $processors = self::list($data['cpu'] ?? []);
        $cores = $threads = 0;

        foreach ($processors as $processor) {
            $cores += self::int($processor['cores'] ?? null);
            $threads += self::int($processor['threads'] ?? null);
        }

        $caption = (string)($os['caption'] ?? 'Unknown Windows');

        return [
            'cpu'       => [
                'model'         => $processors[0]['name'] ?? null,
                'cores'         => $cores,
                'threads'       => $threads,
                'sockets'       => max(1, count($processors)),
                'max_clock_mhz' => isset($processors[0]['max_clock']) ? self::int($processors[0]['max_clock']) : null,
                'architecture'  => PHP_INT_SIZE === 8 ? 'x64' : 'x86',
                'limit_cores'   => null,
            ],
            // NOTE: Win32_VideoController.AdapterRAM is a 32-bit field, so VRAM above 4 GB is under-reported by Windows itself.
            'gpu'       => array_map(static fn(array $g): array => [
                'name'           => $g['name'] ?? 'Unknown',
                'vendor'         => null,
                'memory'         => isset($g['memory']) ? self::int($g['memory']) : null,
                'driver_version' => $g['driver'] ?? null,
            ], self::list($data['gpu'] ?? [])),
            'version'   => [
                'name'         => $caption,
                'version'      => $os['version'] ?? null,
                'build'        => $os['build'] ?? null,
                'architecture' => $os['arch'] ?? null,
                'is_server'    => isset($os['product_type']) ? (int)$os['product_type'] !== 1 : str_contains(strtolower($caption), 'server'),
            ],
            'timeZone'  => (string)($data['timezone'] ?? date_default_timezone_get()),
            'boot_time' => isset($os['boot']) ? self::int($os['boot']) : null,
        ];
    }

    public function uptimeSeconds(): ?int
    {
        // Prefer the cached inventory's boot time: no PowerShell call just to print an uptime.
        $boot = $this->inventory()['boot_time'] ?? null;

        return $boot ? max(0, time() - (int)$boot) : parent::uptimeSeconds();
    }

    // ---- helpers -----------------------------------------------------------------------------

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /**
     * ConvertTo-Json collapses single-element arrays to a bare object on Windows PowerShell 5.1;
     * normalize both shapes to a list.
     *
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (!is_array($value) || $value === []) return [];

        return array_is_list($value) ? array_values(array_filter($value, 'is_array')) : [$value];
    }
}
