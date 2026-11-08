<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Contracts\CollectorInterface;

class PhpInfo implements CollectorInterface
{
    public function collect(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $status = is_array($status) ? $status : null;

        $jit = $status['jit'] ?? null;
        $stats = $status['opcache_statistics'] ?? null;
        $memory = $status['memory_usage'] ?? null;

        return [
            'version'            => PHP_VERSION,
            'sapi'               => PHP_SAPI,
            'thread_safe'        => (bool)PHP_ZTS,
            'architecture'       => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
            'memory_limit'       => ini_get('memory_limit') ?: null,
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'opcache'            => [
                'enabled'        => (bool)($status['opcache_enabled'] ?? false),
                'hit_rate'       => isset($stats['opcache_hit_rate']) ? round((float)$stats['opcache_hit_rate'], 2) : null,
                'memory'         => $memory === null
                    ? null
                    : [
                        'used'   => (int)$memory['used_memory'],
                        'free'   => (int)$memory['free_memory'],
                        'wasted' => (int)$memory['wasted_memory'],
                    ],
                'cached_scripts' => isset($stats['num_cached_scripts']) ? (int)$stats['num_cached_scripts'] : null,
            ],
            'jit'                => [
                // Truth from the engine when OPcache exposes it; ini values only as a fallback.
                'enabled'     => $jit !== null
                    ? (bool)($jit['enabled'] ?? false)
                    : self::iniBytes((string)ini_get('opcache.jit_buffer_size')) > 0,
                'buffer_size' => $jit['buffer_size'] ?? (ini_get('opcache.jit_buffer_size') ?: null),
            ],
            'xdebug'             => extension_loaded('xdebug'),
            'loaded_extensions'  => get_loaded_extensions(),
        ];
    }

    /** "128M" -> bytes. */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) return 0;

        return (int)$m[1] * match (strtoupper($m[2])) {
                'K'     => 1024,
                'M'     => 1048576,
                'G'     => 1073741824,
                default => 1,
            };
    }
}
