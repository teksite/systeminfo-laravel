<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    | Endpoints (prefix defaults to /system-info):
    |   GET info        static + current report            (sections limited by `info_sections`)
    |   GET metrics     one live snapshot                  (?format=json|chartjs&labels=time|iso|ms)
    |   GET history     last N points, Chart.js-ready      (?points=60&since=<epoch ms>)
    |   GET stream-url  short-lived signed URL for the SSE stream
    |   GET stream      Server-Sent Events                 (signed URL only)
    |
    | SECURITY: access is DENIED by default outside the `local` environment. Grant it with ONE of:
    |   - SYSTEM_INFO_TOKEN (>= 16 chars) sent as `Authorization: Bearer <token>`
    |   - a `viewSystemInfo` gate:   Gate::define('viewSystemInfo', fn ($user) => $user?->isAdmin());
    |     (add 'web' (and 'auth') to `middleware` so the session/user is available)
    |   - `allowed_ips` (CIDR supported)
    |   - AuthorizeSystemInfo::using(fn ($request) => ...) for fully custom logic
    */
    'http'    => [
        'enabled'       => env('SYSTEM_INFO_ENABLED', true),
        'prefix'        => env('SYSTEM_INFO_PREFIX', 'system-info'),
        'name'          => 'system-info.',
        'middleware'    => [],
        'token'         => env('SYSTEM_INFO_TOKEN'),
        'allowed_ips'   => [],
        'allow_local'   => true,
        // Requests per minute per IP (0 disables the limiter).
        'rate_limit'    => 120,
        // Sections the `info` endpoint may reveal. Network/DB details are opt-in on purpose.
        'info_sections' => ['hardware', 'os', 'php', 'laravel'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    | Use a shared store (redis, memcached, database, file). With the `array` store the previous
    | sample is lost between requests, so every request pays the short sampling window.
    */
    'cache'   => [
        'store'         => env('SYSTEM_INFO_CACHE_STORE'), // null = your default cache store
        'prefix'        => 'system-info:',
        'inventory_ttl' => 3600, // CPU model, OS, GPU, ... never change at runtime
        'database_ttl'  => 300,  // database size can be an expensive query
    ],

    /*
    |--------------------------------------------------------------------------
    | Live sampling
    |--------------------------------------------------------------------------
    */
    'live'    => [
        // Pollers closer together than this share ONE sample (stampede protection).
        'min_interval'     => 1.0,
        // Only used on a cold cache: wait this long between the two initial samples.
        'sample_window_ms' => 250,
        // A previous sample older than this is discarded (rates over long gaps are meaningless).
        'max_sample_age'   => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | History (ring buffer used to pre-fill charts)
    |--------------------------------------------------------------------------
    | points x resolution = window, e.g. 300 x 2s = last 10 minutes.
    */
    'history' => [
        'enabled'    => true,
        'points'     => 300,
        'resolution' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server-Sent Events
    |--------------------------------------------------------------------------
    | Each open stream occupies one PHP worker for up to `max_duration` seconds. Prefer polling
    | `metrics` if your worker pool is small.
    */
    'stream'  => [
        'default_interval' => 2,
        'min_interval'     => 1,
        'max_interval'     => 30,
        'max_duration'     => 55,
        'url_ttl'          => 300,
        // Relative signed URLs survive proxies/load balancers that rewrite scheme or host.
        'relative_url'     => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    | `paths` = null auto-detects real local filesystems. Network filesystems are skipped by default
    | because a hung NFS mount can block statvfs() and with it the whole request.
    */
    'disk'    => [
        'paths'              => null, // e.g. ['/', '/var/www', storage_path()]
        'include_network_fs' => false,
        'exclude_mounts'     => [
            '/var/lib/docker/*', '/var/lib/containers/*', '/var/lib/kubelet/*',
            '/snap/*', '/run/*', '/sys/*', '/proc/*', '/dev/*',
            '/etc/hosts', '/etc/hostname', '/etc/resolv.conf',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Network
    |--------------------------------------------------------------------------
    | Interfaces matching these globs are ignored for throughput and local IP detection.
    | `public_ip` performs an OUTBOUND HTTPS request to a third party: disabled by default.
    */
    'network' => [
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
        'size' => true, // set false to skip the size query on very large databases
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart.js output
    |--------------------------------------------------------------------------
    | labels: time (HH:MM:SS) | iso (ISO-8601) | ms (epoch milliseconds, for a time scale)
    */
    'chart'    => [
        'labels' => 'time',
        'colors' => ['#3b82f6', '#ef4444', '#10b981', '#f59e0b', '#8b5cf6'],
    ],

    'shell' => [
        'timeout'            => 5.0,  // nvidia-smi / lspci
        'powershell_timeout' => 15.0, // Windows only
    ],
];
