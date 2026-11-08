<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Metrics\MetricsCalculator;
use Teksite\SystemInfo\Support\Glob;
use Teksite\SystemInfo\Support\Options;
use Teksite\SystemInfo\Support\SafeExecutor;

/**
 * Linux driver. Reads /proc and /sys directly: no shell, no process spawn, microseconds per
 * sample. The only external binaries (nvidia-smi, lspci) are used for GPU detection, which is
 * part of the cached inventory and never runs on the live path.
 *
 * $root exists so tests can point the driver at a fixture tree.
 */
class LinuxDriver extends AbstractDriver
{
    private const array LOCAL_FS = [
        'ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'f2fs', 'jfs', 'reiserfs',
        'ntfs', 'ntfs3', 'fuseblk', 'vfat', 'exfat', 'overlay',
    ];

    private const array NETWORK_FS = ['nfs', 'nfs4', 'cifs', 'smb3', 'glusterfs', 'ceph', 'fuse.sshfs'];

    public function __construct(Options|array|null $options = null, private readonly string $root = '')
    {
        parent::__construct($options);
    }

    public function family(): string
    {
        return 'Linux';
    }

    // ---- raw access --------------------------------------------------------------------------

    protected function read(string $path): ?string
    {
        $file = $this->root.$path;

        if (!@is_readable($file)) return null;

        $content = @file_get_contents($file);

        return $content === false ? null : $content;
    }

    // ---- live sample -------------------------------------------------------------------------

    public function sample(): array
    {
        $time = microtime(true);
        $meminfo = $this->parseMeminfo($this->read('/proc/meminfo'));
        $load = $this->read('/proc/loadavg');
        $uptime = $this->read('/proc/uptime');

        return [
            'time'      => $time,
            'cpu'       => $this->parseStat($this->read('/proc/stat')),
            'load'      => $load !== null && preg_match('/^([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $load, $m)
                ? [(float)$m[1], (float)$m[2], (float)$m[3]]
                : $this->loadAverage(),
            'memory'    => $meminfo,
            'network'   => $this->parseNetDev($this->read('/proc/net/dev')),
            'disk_io'   => $this->parseDiskstats($this->read('/proc/diskstats')),
            'disks'     => $this->disks(),
            'container' => $this->containerMemory($meminfo['total'] ?? null),
            'uptime'    => $uptime !== null ? (int)floor((float)strtok($uptime, ' ')) : null,
        ];
    }

    /** @return ?array{total: array<string,int>, cores: list<array<string,int>>} */
    public function parseStat(?string $stat): ?array
    {
        if ($stat === null) return null;

        $total = null;
        $cores = [];

        foreach (explode("\n", $stat) as $line) {

            if (!str_starts_with($line, 'cpu')) continue;


            $parts = preg_split('/\s+/', trim($line));
            $label = array_shift($parts);
            $v = array_pad(array_map('intval', $parts), 8, 0);

            // guest/guest_nice are already part of user/nice, so they are intentionally ignored.
            $entry = [
                'user'   => $v[0] + $v[1],
                'system' => $v[2] + $v[5] + $v[6],
                'idle'   => $v[3],
                'iowait' => $v[4],
                'steal'  => $v[7],
            ];

            if ($label === 'cpu') {
                $total = $entry;
            } else {
                $cores[] = $entry;
            }
        }

        return $total === null ? null : ['total' => $total, 'cores' => $cores];
    }

    /** @return ?array{total: int, available: int, cached: int, swap_total: int, swap_used: int} bytes */
    public function parseMeminfo(?string $text): ?array
    {
        if ($text === null || !preg_match_all('/^(\w+)(?:\(\w+\))?:\s+(\d+)/m', $text, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $kb = [];
        foreach ($matches as $m) {
            $kb[$m[1]] = (int)$m[2];
        }

        $total = $kb['MemTotal'] ?? 0;

        if ($total <= 0) return null;


        // MemAvailable exists on kernels >= 3.14; fall back to the classic estimate otherwise.
        $available = $kb['MemAvailable'] ?? (($kb['MemFree'] ?? 0) + ($kb['Buffers'] ?? 0) + ($kb['Cached'] ?? 0));
        $cached = ($kb['Cached'] ?? 0) + ($kb['Buffers'] ?? 0) + ($kb['SReclaimable'] ?? 0);
        $swapTotal = $kb['SwapTotal'] ?? 0;

        return [
            'total'      => $total * 1024,
            'available'  => $available * 1024,
            'cached'     => $cached * 1024,
            'swap_total' => $swapTotal * 1024,
            'swap_used'  => max(0, $swapTotal - ($kb['SwapFree'] ?? 0)) * 1024,
        ];
    }

    /** @return ?array{rx: int, tx: int} cumulative bytes over all non-virtual interfaces */
    public function parseNetDev(?string $text): ?array
    {
        if ($text === null) return null;

        $exclude = (array)$this->options->get('network.exclude_interfaces', []);
        $rx = $tx = 0;
        $seen = false;

        foreach (explode("\n", $text) as $line) {

            if (!str_contains($line, ':')) continue;

            [$name, $rest] = explode(':', $line, 2);
            $fields = preg_split('/\s+/', trim($rest));

            if (count($fields) < 16 || Glob::matchAny($exclude, trim($name))) continue;

            $rx += (int)$fields[0];
            $tx += (int)$fields[8];
            $seen = true;
        }

        return $seen ? ['rx' => $rx, 'tx' => $tx] : ['rx' => 0, 'tx' => 0];
    }

    /** @return ?array{read: int, write: int} cumulative bytes over whole physical disks only */
    public function parseDiskstats(?string $text): ?array
    {
        if ($text === null) return null;

        $read = $write = 0;

        foreach (explode("\n", $text) as $line) {
            $f = preg_split('/\s+/', trim($line));

            // Whole disks only: partitions would be counted twice, dm-/loop/ram are virtual.
            if (count($f) < 10 || !preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|hd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $f[2])) {
                continue;
            }

            $read += (int)$f[5] * 512;
            $write += (int)$f[9] * 512;
        }

        return ['read' => $read, 'write' => $write];
    }

    /**
     * cgroup (v2, then v1) memory limit - the number that actually matters inside Docker/K8s.
     *
     * @return ?array{memory: array{limit: int, used: int, percent: float}}
     */
    protected function containerMemory(?int $hostTotal): ?array
    {
        $limit = $used = $inactive = null;

        $v2Max = $this->read('/sys/fs/cgroup/memory.max');

        if ($v2Max !== null) {
            $limit = is_numeric(trim($v2Max)) ? (int)trim($v2Max) : null;
            $used = (int)trim((string)$this->read('/sys/fs/cgroup/memory.current'));
            $inactive = $this->statValue($this->read('/sys/fs/cgroup/memory.stat'), 'inactive_file');
        } else {
            $v1Limit = $this->read('/sys/fs/cgroup/memory/memory.limit_in_bytes');

            if ($v1Limit !== null && is_numeric(trim($v1Limit))) {
                $limit = (int)trim($v1Limit);
                $used = (int)trim((string)$this->read('/sys/fs/cgroup/memory/memory.usage_in_bytes'));
                $inactive = $this->statValue($this->read('/sys/fs/cgroup/memory/memory.stat'), 'total_inactive_file');
            }
        }

        // No limit set (v1 reports ~2^63, v2 reports "max") or a limit above the host's RAM.
        if ($limit === null || $limit <= 0 || $limit >= PHP_INT_MAX / 2 || ($hostTotal !== null && $limit >= $hostTotal)) {
            return null;
        }

        // Same accounting as `docker stats`: usage minus reclaimable page cache.
        $used = max(0, (int)$used - (int)$inactive);

        return ['memory' => ['limit' => $limit, 'used' => $used, 'percent' => MetricsCalculator::percent($used, $limit)]];
    }

    private function statValue(?string $stat, string $key): int
    {
        return $stat !== null && preg_match('/^'.preg_quote($key, '/').'\s+(\d+)/m', $stat, $m) ? (int)$m[1] : 0;
    }

    // ---- disks -------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    protected function disks(): array
    {
        $entries = [];

        if (($paths = $this->configuredDiskPaths()) !== null) {
            foreach ($paths as $path) {
                $entries[] = ['path' => $path, 'device' => null, 'fs' => null];
            }
        } else {
            $entries = $this->mountPoints();
        }

        $disks = [];

        foreach ($entries as $entry) {
            if (($usage = $this->diskUsage($entry['path'], $entry['device'], $entry['fs'])) !== null) {
                $disks[] = $usage;
            }
        }

        return $disks;
    }

    /**
     * Real, local, de-duplicated mounts from /proc/mounts.
     *
     * @return list<array{path: string, device: ?string, fs: string}>
     */
    public function mountPoints(): array
    {
        $mounts = $this->read('/proc/mounts');

        if ($mounts === null) return [['path' => '/', 'device' => null, 'fs' => 'unknown']];

        $allowed = self::LOCAL_FS;

        if ($this->options->get('disk.include_network_fs', false)) {
            $allowed = [...$allowed, ...self::NETWORK_FS];
        }

        $exclude = (array)$this->options->get('disk.exclude_mounts', []);
        $unique = [];

        foreach (explode("\n", $mounts) as $line) {
            $f = preg_split('/\s+/', trim($line));

            if (count($f) < 3 || !in_array($f[2], $allowed, true)) continue;


            // /proc/mounts escapes spaces and tabs as octal (e.g. "\040").
            $mountPoint = preg_replace_callback('/\\\\([0-7]{3})/', static fn(array $m): string => chr((int)octdec($m[1])), $f[1]);

            if (Glob::matchAny($exclude, $mountPoint) || !$this->isDirectory($mountPoint)) {
                continue;
            }
            // The same block device can be mounted many times (bind mounts, containers): keep the shortest path.
            $key = str_starts_with($f[0], '/dev/') ? $f[0] : $mountPoint;

            if (!isset($unique[$key]) || strlen($mountPoint) < strlen($unique[$key]['path'])) {
                $unique[$key] = ['path' => $mountPoint, 'device' => str_starts_with($f[0], '/dev/') ? $f[0] : null, 'fs' => $f[2]];
            }
        }

        return array_values($unique) ?: [['path' => '/', 'device' => null, 'fs' => 'unknown']];
    }

    // ---- static inventory --------------------------------------------------------------------

    protected function buildBase(): array
    {
        return [
            'cpu'       => $this->parseCpuInfo($this->read('/proc/cpuinfo')),
            'gpu'       => $this->detectGpu(),
            'version'   => $this->osVersion(),
            'timeZone'  => $this->detectTimeZone(),
            'boot_time' => ($up = $this->read('/proc/uptime')) !== null ? time() - (int)floor((float)strtok($up, ' ')) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function parseCpuInfo(?string $text): array
    {
        $info = [
            'model'         => null,
            'cores'         => 0,
            'threads'       => 0,
            'sockets'       => 1,
            'max_clock_mhz' => null,
            'architecture'  => php_uname('m'),
            'limit_cores'   => $this->cpuLimit(),
        ];

        if ($text === null) return $info;

        $threads = 0;
        $model = null;
        $cores = $sockets = [];
        $maxMhz = null;

        foreach (preg_split('/\n\s*\n/', trim($text)) as $block) {

            if (!preg_match('/^processor\s*:/m', $block)) continue;

            $threads++;
            $get = static fn(string $key): ?string => preg_match('/^'.$key.'\s*:\s*(.+)$/mi', $block, $m) ? trim($m[1]) : null;

            $model ??= $get('model name');

            $physical = $get('physical id');
            $core = $get('core id');

            if ($physical !== null) {
                $sockets[$physical] = true;
            }
            if ($physical !== null && $core !== null) {
                $cores[$physical.':'.$core] = true;
            }
            if (($mhz = $get('cpu MHz')) !== null) {
                $maxMhz = max($maxMhz ?? 0.0, (float)$mhz);
            }
        }

        // ARM / some VMs have no "model name": fall back to Hardware / Model lines.
        if ($model === null && preg_match('/^(?:Hardware|Model)\s*:\s*(.+)$/mi', $text, $m)) {
            $model = trim($m[1]);
        }

        $sysfsMax = $this->read('/sys/devices/system/cpu/cpu0/cpufreq/cpuinfo_max_freq');

        $info['model'] = $model;
        $info['threads'] = $threads;
        $info['cores'] = count($cores) ?: $threads;
        $info['sockets'] = max(1, count($sockets));
        $info['max_clock_mhz'] = $sysfsMax !== null ? (int)round((int)trim($sysfsMax) / 1000) : ($maxMhz !== null ? (int)round($maxMhz) : null);

        return $info;
    }

    /** CPU quota imposed by cgroups (Docker `--cpus`, K8s limits); null when unlimited. */
    protected function cpuLimit(): ?float
    {
        $v2 = $this->read('/sys/fs/cgroup/cpu.max');

        if ($v2 !== null && preg_match('/^(\d+)\s+(\d+)/', trim($v2), $m) && (int)$m[2] > 0) {
            return round((int)$m[1] / (int)$m[2], 2);
        }

        $quota = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
        $period = $this->read('/sys/fs/cgroup/cpu/cpu.cfs_period_us');

        if ($quota !== null && $period !== null && (int)trim($quota) > 0 && (int)trim($period) > 0) {
            return round((int)trim($quota) / (int)trim($period), 2);
        }

        return null;
    }

    /** @return array{name: string, version: ?string, id: ?string, kernel: string, architecture: string} */
    public function osVersion(): array
    {
        $release = [];

        // parse_ini_file() chokes on unquoted values with $, !, ( ... which os-release allows.
        foreach (explode("\n", (string)($this->read('/etc/os-release') ?? $this->read('/usr/lib/os-release'))) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
                $release[$m[1]] = stripcslashes(trim($m[2], "\"'"));
            }
        }

        return [
            'name'         => $release['PRETTY_NAME'] ?? $release['NAME'] ?? 'Linux',
            'version'      => $release['VERSION_ID'] ?? null,
            'id'           => $release['ID'] ?? null,
            'kernel'       => php_uname('r'),
            'architecture' => php_uname('m'),
        ];
    }

    protected function detectTimeZone(): string
    {
        $link = @readlink($this->root.'/etc/localtime');

        if (is_string($link) && ($pos = strpos($link, 'zoneinfo/')) !== false) {
            return substr($link, $pos + 9);
        }

        $file = $this->read('/etc/timezone');

        if ($file !== null && trim($file) !== '') return trim($file);

        return date_default_timezone_get();
    }

    /** @return list<array{name: string, vendor: ?string, memory: ?int, driver_version: ?string}> */
    protected function detectGpu(): array
    {
        $timeout = (float)$this->options->get('shell.timeout', 5.0);

        if ($this->root === '' && SafeExecutor::commandExists('nvidia-smi')) {
            $out = SafeExecutor::run(
                ['nvidia-smi', '--query-gpu=name,memory.total,driver_version', '--format=csv,noheader,nounits'],
                $timeout
            );

            if ($out !== null) {
                $gpus = [];

                foreach (explode("\n", $out) as $line) {
                    $parts = array_map('trim', explode(',', $line));

                    if (count($parts) >= 3 && $parts[0] !== '') {
                        $gpus[] = [
                            'name'           => $parts[0],
                            'vendor'         => 'NVIDIA',
                            'memory'         => is_numeric($parts[1]) ? (int)$parts[1] * 1048576 : null,
                            'driver_version' => $parts[2] ?: null,
                        ];
                    }
                }

                if ($gpus !== []) return $gpus;

            }
        }

        if ($this->root !== '' || !SafeExecutor::commandExists('lspci')) return [];


        $out = SafeExecutor::run(['lspci', '-mm'], $timeout);
        $gpus = [];

        foreach (explode("\n", (string)$out) as $line) {
            if (preg_match('/^\S+\s+"(VGA compatible controller|3D controller|Display controller)"\s+"([^"]*)"\s+"([^"]*)"/i', $line, $m)) {
                $gpus[] = ['name' => $m[3], 'vendor' => $m[2], 'memory' => null, 'driver_version' => null];
            }
        }

        return $gpus;
    }

    private function isDirectory(string $path): bool
    {
        $fixturePath =rtrim($this->root, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'));

        return is_dir($fixturePath);

    }

}
