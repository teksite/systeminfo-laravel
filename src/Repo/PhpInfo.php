<?php

namespace Teksite\SystemInfo\Repo;

class PhpInfo {
    public function collect(): array
    {
        $opcache = function_exists('opcache_get_status')
            ? @opcache_get_status(false)
            : false;

        return [
            'version' => PHP_VERSION,

            'sapi' => php_sapi_name(),

            'thread_safe' => PHP_ZTS,

            'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',

            'memory_limit' => ini_get('memory_limit'),

            'max_execution_time' => (int) ini_get('max_execution_time'),

            'opcache' => [
                'enabled' => (bool) ($opcache['opcache_enabled'] ?? false),
            ],

            'jit' => [
                'enabled' => (
                    (int) ini_get('opcache.jit_buffer_size') > 0
                ),
                'buffer_size' => ini_get('opcache.jit_buffer_size'),
            ],

            'loaded_extensions' => get_loaded_extensions(),

        ];
    }
}
