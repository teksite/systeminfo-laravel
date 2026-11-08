<?php

namespace Teksite\SystemInfo\Support;

/**
 * Immutable, deep-merged view of the package configuration.
 *
 * Laravel's mergeConfigFrom() is shallow: a published config that overrides a single
 * nested key would silently drop all sibling defaults. Merging here keeps every
 * option safe to omit.
 */
final class Options
{
    public const array DEFAULTS = [
        'http'     => [
            'enabled'       => true,
            'prefix'        => 'system-info',
            'name'          => 'system-info.',
            'middleware'    => [],
            'token'         => null,
            'allowed_ips'   => [],
            'allow_local'   => true,
            'rate_limit'    => 120,
            'info_sections' => ['hardware', 'os', 'php', 'laravel'],
        ],
        'cache'    => [
            'store'         => null,
            'prefix'        => 'system-info:',
            'inventory_ttl' => 3600,
            'database_ttl'  => 300,
        ],
        'live'     => [
            'min_interval'     => 1.0,
            'sample_window_ms' => 250,
            'max_sample_age'   => 15,
        ],
        'history'  => [
            'enabled'    => true,
            'points'     => 300,
            'resolution' => 2.0,
        ],
        'stream'   => [
            'default_interval' => 2,
            'min_interval'     => 1,
            'max_interval'     => 30,
            'max_duration'     => 55,
            'url_ttl'          => 300,
            'relative_url'     => true,
        ],
        'disk'     => [
            'paths'              => null,
            'include_network_fs' => false,
            'exclude_mounts'     => [
                '/var/lib/docker/*', '/var/lib/containers/*', '/var/lib/kubelet/*',
                '/snap/*', '/run/*', '/sys/*', '/proc/*', '/dev/*',
                '/etc/hosts', '/etc/hostname', '/etc/resolv.conf',
            ],
        ],
        'network'  => [
            'exclude_interfaces' => [
                'lo', 'docker*', 'veth*', 'br-*', 'virbr*', 'cni*', 'flannel*', 'cali*',
                '*Loopback*', '*isatap*', '*Teredo*', '*Pseudo*', '*Virtual Switch*',
            ],
            'public_ip'          => [
                'enabled'  => false,
                'endpoint' => 'https://api.ipify.org',
                'timeout'  => 2.0,
                'ttl'      => 3600,
            ],
        ],
        'database' => [
            'size' => true,
        ],
        'chart'    => [
            'labels' => 'time',
            'colors' => ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6'],
        ],
        'shell'    => [
            'timeout'            => 5.0,
            'powershell_timeout' => 15.0,
        ],
    ];

    /** @var array<string, mixed> */
    private array $items;

    /** @param array<string, mixed> $overrides */
    public function __construct(array $overrides = [])
    {
        $this->items = self::merge(self::DEFAULTS, $overrides);
    }

    public static function from(self|array|null $options): self
    {
        return $options instanceof self ? $options : new self($options ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {

            if (!is_array($value) || !array_key_exists($segment, $value))   return $default;

            $value = $value[$segment];
        }

        return $value ?? $default;
    }


    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }


    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $baseIsMap = isset($base[$key]) && is_array($base[$key]) && self::isMap($base[$key]);

            if ($baseIsMap && is_array($value)) {
                // An empty array never wipes a whole section of defaults.
                $base[$key] = $value === [] ? $base[$key] : self::merge($base[$key], $value);
            } else {
                $base[$key] = $value; // scalars and lists replace
            }
        }
        return $base;
    }


    private static function isMap(array $array): bool
    {
        return $array !== [] && !array_is_list($array);
    }
}
