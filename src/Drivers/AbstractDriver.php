<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Concerns\Calculates;
use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Metrics\MetricsCalculator;
use Teksite\SystemInfo\Support\Glob;
use Teksite\SystemInfo\Support\Options;

/**
 * Everything that is identical on every platform lives here; subclasses only implement what
 * genuinely differs: the raw sample and the static inventory.
 */
abstract class AbstractDriver implements DriverInterface
{
    use Calculates;

    protected Options $options;

    /** @var ?array<string, mixed> */
    private ?array $baseMemo = null;

    /** @var ?array<string, mixed> */
    private ?array $inventoryMemo = null;

    /** @var ?array<string, mixed> */
    private ?array $sampleMemo = null;

    public function __construct(Options|array|null $options = null)
    {
        $this->options = Options::from($options);
    }

    /**
     * Platform specific, static data: ['cpu' => [...], 'gpu' => [...], 'version' => [...],
     * 'timeZone' => string, 'boot_time' => ?int].
     *
     * @return array<string, mixed>
     */
    abstract protected function buildBase(): array;

    /** @return array<string, mixed> */
    abstract public function sample(): array;

    // ---- inventory ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function base(): array
    {
        return $this->baseMemo ??= $this->buildBase();
    }

    public function inventory(bool $fresh = false): array
    {
        if ($fresh) {
            $this->baseMemo = $this->inventoryMemo = null;
        }

        return $this->inventoryMemo ??= (function (): array {
            $base = $this->base();
            $interfaces = $this->networkInterfaces();

            return [
                'cpu'       => $base['cpu'],
                'gpu'       => $base['gpu'],
                'os'        => [
                    'family'       => $this->family(),
                    'hostname'     => $this->hostname(),
                    'version'      => $base['version'],
                    'timeZone'     => $base['timeZone'],
                    'architecture' => php_uname('m'),
                ],
                'network'   => ['local_ip' => $this->pickLocalIp($interfaces), 'interfaces' => $interfaces],
                'boot_time' => $base['boot_time'] ?? null,
            ];
        })();
    }

    public function hostname(): string
    {
        return gethostname() ?: php_uname('n');
    }

    public function timeZone(): string
    {
        return (string)($this->base()['timeZone'] ?? date_default_timezone_get());
    }

    public function version(): array
    {
        return $this->base()['version'];
    }

    public function cpu(): array
    {
        return $this->base()['cpu'] + ['load' => MetricsCalculator::load($this->currentLoad())];
    }

    /** @return ?list<float> */
    protected function currentLoad(): ?array
    {
        return $this->currentSample()['load'] ?? null;
    }

    public function gpu(): array
    {
        return $this->base()['gpu'];
    }

    // ---- current usage -----------------------------------------------------------------------

    /**
     * Memoised for one second so ram()/swap()/disk()/collector() share a single sample
     * instead of re-reading /proc (or, on Windows, re-spawning PowerShell) per call.
     *
     * @return array<string, mixed>
     */
    protected function currentSample(float $maxAge = 1.0): array
    {
        if ($this->sampleMemo === null || (microtime(true) - $this->sampleMemo['time']) > $maxAge) {
            $this->sampleMemo = $this->sample();
        }

        return $this->sampleMemo;
    }

    public function ram(): array
    {
        return MetricsCalculator::memory($this->currentSample()['memory'] ?? null);
    }

    public function swap(): array
    {
        return MetricsCalculator::swap($this->currentSample()['memory'] ?? null);
    }

    public function disk(): array
    {
        return array_values($this->currentSample()['disks'] ?? []);
    }

    public function uptimeSeconds(): ?int
    {
        $uptime = $this->currentSample()['uptime'] ?? null;

        return $uptime === null ? null : (int)$uptime;
    }

    public function upTime(): ?string
    {
        return $this->secondToHumans($this->uptimeSeconds());
    }

    /** @return ?list<float> */
    protected function loadAverage(): ?array
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return is_array($load) ? array_map('floatval', array_values($load)) : null;
    }

    /**
     * @return ?array{path: string, device: ?string, fs: ?string, total: int, used: int, free: int, percent: float}
     */
    protected function diskUsage(string $path, ?string $device = null, ?string $fs = null): ?array
    {
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if (!is_float($total) || !is_float($free) || $total <= 0) {
            return null;
        }

        $used = max(0.0, $total - $free);

        return [
            'path'    => $path,
            'device'  => $device,
            'fs'      => $fs,
            'total'   => (int)$total,
            'used'    => (int)$used,
            'free'    => (int)$free,
            'percent' => MetricsCalculator::percent($used, $total),
        ];
    }

    /** @return list<string>|null user-configured disk paths, or null for auto-detection */
    protected function configuredDiskPaths(): ?array
    {
        $paths = $this->options->get('disk.paths');

        if (!is_array($paths) || $paths === []) {
            return null;
        }

        return array_values(array_filter($paths, 'is_string'));
    }

    // ---- network -----------------------------------------------------------------------------

    /**
     * Pure PHP (net_get_interfaces) - replaces the old gethostbyname(gethostname()), which does a
     * blocking DNS lookup and usually returns 127.0.1.1.
     *
     * @return array<string, array{ipv4: list<string>, ipv6: list<string>}>
     */
    protected function networkInterfaces(): array
    {
        if (!function_exists('net_get_interfaces')) {
            return [];
        }

        $raw = @net_get_interfaces();

        if (!is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $name => $info) {
            if (!($info['up'] ?? false)) {
                continue;
            }

            $v4 = $v6 = [];

            foreach ($info['unicast'] ?? [] as $addr) {
                $ip = explode('%', (string)($addr['address'] ?? ''))[0];

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $v4[] = $ip;
                } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $v6[] = $ip;
                }
            }

            $result[(string)$name] = ['ipv4' => $v4, 'ipv6' => $v6];
        }

        return $result;
    }

    /** @param array<string, array{ipv4: list<string>, ipv6: list<string>}> $interfaces */
    private function pickLocalIp(array $interfaces): ?string
    {
        $exclude = (array)$this->options->get('network.exclude_interfaces', []);

        foreach ($interfaces as $name => $addresses) {
            if (Glob::matchAny($exclude, (string)$name)) {
                continue;
            }

            foreach ($addresses['ipv4'] as $ip) {
                if (!str_starts_with($ip, '127.') && !str_starts_with($ip, '169.254.')) {
                    return $ip;
                }
            }
        }

        $serverAddr = $_SERVER['SERVER_ADDR'] ?? null;

        return is_string($serverAddr) && filter_var($serverAddr, FILTER_VALIDATE_IP) ? $serverAddr : null;
    }

    public function localIp(): ?string
    {
        return $this->inventory()['network']['local_ip'];
    }

    public function publicIp(): ?string
    {
        if (!$this->options->get('network.public_ip.enabled', false)) {
            return null;
        }

        $endpoint = (string)$this->options->get('network.public_ip.endpoint', '');
        $timeout = max(0.5, (float)$this->options->get('network.public_ip.timeout', 2.0));

        // https only: never send the server's request anywhere in clear text.
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return null;
        }

        $body = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER    => true,
                CURLOPT_TIMEOUT_MS        => (int)($timeout * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int)($timeout * 1000),
                CURLOPT_FOLLOWLOCATION    => false,
                CURLOPT_MAXFILESIZE       => 1024,
            ]);
            $result = curl_exec($ch);
            $body = is_string($result) ? $result : null;
            curl_close($ch);
        } elseif (ini_get('allow_url_fopen')) {
            $context = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 0]]);
            $result = @file_get_contents($endpoint, false, $context, 0, 1024);
            $body = is_string($result) ? $result : null;
        }

        $ip = $body === null ? '' : trim($body);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    // ---- web server --------------------------------------------------------------------------

    public function software(): ?string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;

        return is_string($software) && $software !== '' ? $software : null;
    }

    public function phpSapiName(): ?string
    {
        return PHP_SAPI;
    }

    public function webServer(): array
    {
        $s = strtolower($this->software() ?? '');
        $open = str_contains($s, 'openlitespeed');

        return [
            'apache'        => str_contains($s, 'apache'),
            'nginx'         => str_contains($s, 'nginx'),
            'litespeed'     => !$open && str_contains($s, 'litespeed'),
            'openlitespeed' => $open,
            'iis'           => str_contains($s, 'microsoft-iis'),
            'caddy'         => str_contains($s, 'caddy'),
            'frankenphp'    => str_contains($s, 'frankenphp'),
            'php_builtin'   => str_contains($s, 'development server'),
        ];
    }

    // ---- one-shot dump -----------------------------------------------------------------------

    public function collector(): array
    {
        $sample = $this->currentSample();

        return [
            'hardware'   => [
                'cpu'  => $this->cpu(),
                'ram'  => $this->ram(),
                'swap' => $this->swap(),
                'disk' => $this->disk(),
                'gpu'  => $this->gpu(),
            ],
            'network'    => [
                'localIp'  => $this->localIp(),
                'publicIp' => $this->publicIp(),
            ],
            'os'         => [
                'family'   => $this->family(),
                'hostname' => $this->hostname(),
                'version'  => $this->version(),
                'timeZone' => $this->timeZone(),
                'upTime'   => $this->upTime(),
            ],
            'web_server' => [
                'software'      => $this->software(),
                'php_sapi_name' => $this->phpSapiName(),
                'detect'        => $this->webServer(),
            ],
            'meta'       => [
                'timestamp' => (int)$sample['time'],
                'datetime'  => date('Y-m-d H:i:s'),
            ],
        ];
    }
}
