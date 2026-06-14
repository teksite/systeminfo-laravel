<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Repo\LinuxHardware;
use Teksite\SystemInfo\Repo\LinuxNetwork;
use Teksite\SystemInfo\Repo\LinuxOS;
use Teksite\SystemInfo\Repo\LinuxUptime;
use Teksite\SystemInfo\Repo\LinuxWebServer;

readonly class LinuxDriver implements DriverInterface
{
    protected LinuxHardware $hardware;
    protected LinuxOS $os;
    protected LinuxWebServer $webserver;
    protected LinuxUptime $upTime;

    protected LinuxNetwork $network;

    public function __construct()
    {
        $this->hardware = new LinuxHardware;
        $this->os = new LinuxOS;
        $this->webserver = new LinuxWebServer();
        $this->upTime = new LinuxUptime();
        $this->network = new LinuxNetwork();
    }

    public function cpu(): array
    {
        return $this->hardware->cpu();
    }

    public function ram(): array
    {
        return $this->hardware->ram();
    }

    public function disk(): array
    {
        return $this->hardware->disk();
    }

    public function gpu(): ?array
    {
        return $this->hardware->gpu();
    }

    public function family(): string
    {
        return $this->os->family();
    }

    public function hostname(): string
    {
        return $this->os->hostname();
    }

    public function version(): array
    {
        return $this->os->version();
    }

    public function timeZone(): string
    {
        return $this->os->timeZone();
    }

    public function software(): ?string
    {
        return $this->webserver->software();
    }

    public function phpSapiName(): ?string
    {
        return $this->webserver->phpSapi();
    }

    public function webServer(): array
    {
        return $this->webserver->detect();
    }

    public function upTime(): ?string
    {
        return $this->upTime->human();
    }

    public function localIp(): ?string
    {
        return $this->network->localIp();
    }

    public function publicIp(): ?string
    {
        return $this->network->publicIp();
    }


    public function collector(): array
    {
        return [
            'hardware'   => [
                'cpu'  => $this->cpu(),
                'ram'  => $this->ram(),
                'disk' => $this->disk(),
                'gpu'  => $this->gpu(),
            ],
            'network'    => [
                'localIp'  => $this->localIp(),
                'publicIp'  => $this->publicIp(),
            ],
            'os'         => [
                'family'   => $this->family(),
                'hostname' => $this->hostname(),
                'version'  => $this->version(),
                'timeZone' => $this->timeZone(),
                'upTime'  => $this->upTime(),

            ],
            'web_server' => [
                'software'      => $this->software(),
                'php_sapi_name' => $this->phpSapiName(),
                'detect'        => $this->webServer(),
            ],
            'meta' => [
                'timestamp' => time(),
                'datetime' => date('Y-m-d H:i:s'),
            ],
        ];
    }

}
