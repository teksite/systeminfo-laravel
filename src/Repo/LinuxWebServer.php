<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxWebServer
{
    public function software(): ?string
    {
        return $_SERVER['SERVER_SOFTWARE'] ?? null;
    }

    public function detect(): array
    {
        $software = strtolower($_SERVER['SERVER_SOFTWARE'] ?? '');

        return [
            'apache'        => str_contains($software, 'apache'),
            'nginx'         => str_contains($software, 'nginx'),
            'litespeed'     => str_contains($software, 'litespeed'),
            'iis'           => str_contains($software, 'iis'),
            'openlitespeed' => str_contains($software, 'openlitespeed'),
            'caddy'         => str_contains($software, 'caddy'),
        ];
    }

    public function version(): ?string
    {
        return $_SERVER['SERVER_SOFTWARE'] ?? null;
    }

    public function phpSapi(): string
    {
        return php_sapi_name();
    }
}
