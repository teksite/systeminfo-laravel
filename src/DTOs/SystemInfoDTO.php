<?php

namespace Teksite\SystemInfo\DTOs;

readonly class SystemInfoDTO
{
    public function __construct(
        // Laravel & PHP
        public string $laravelVersion,
        public string $phpVersion,
        public array  $phpModules,

        // Database
        public string $dbDriver,
        public string $dbVersion,
        public string $dbName,

        // OS
        public array  $os,

        // CPU
        public array  $cpu,

        // RAM
        public array  $ram,

        // Disk
        public array  $disks,

        // Web Server
        public array  $webServer,

        // Time
        public string $serverTime,
        public string $appTime,
        public string $appTimezone,

        // App Config
        public string $cacheDriver,
        public string $sessionDriver,
        public string $environment,
    ) {}

    public function toArray(): array
    {
        return [
            'laravel'  => [
                'version'     => $this->laravelVersion,
                'environment' => $this->environment,
            ],
            'php'      => [
                'version' => $this->phpVersion,
                'modules' => $this->phpModules,
            ],
            'database' => [
                'driver'  => $this->dbDriver,
                'version' => $this->dbVersion,
                'name'    => $this->dbName,
            ],
            'os'       => $this->os,
            'cpu'      => $this->cpu,
            'ram'      => $this->ram,
            'disks'    => $this->disks,
            'server'   => [
                'web_server' => $this->webServer,
                'time'       => $this->serverTime,
            ],
            'app'      => [
                'time'           => $this->appTime,
                'timezone'       => $this->appTimezone,
                'cache_driver'   => $this->cacheDriver,
                'session_driver' => $this->sessionDriver,
            ],
        ];
    }
}
