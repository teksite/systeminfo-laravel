<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Repo\WindowsHardware;
use Teksite\SystemInfo\Repo\WindowsOS;
use Teksite\SystemInfo\Repo\WindowsWebServer;

readonly class WindowsDriver implements DriverInterface
{
    protected WindowsHardware $hardware;
    protected WindowsOS $os;
    protected WindowsWebServer $webserver;


    public function __construct()
    {
        $this->hardware = new WindowsHardware();
        $this->os = new WindowsOS();
        $this->webserver = new WindowsWebServer();

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

    public function gpu(): array
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

    public function php_sapi_name(): ?string
    {
        return $this->webserver->software();
    }

    public function webServer(): array
    {
        return $this->webserver->detect();
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
            'os'         => [
                'family'   => $this->family(),
                'hostname' => $this->hostname(),
                'version'  => $this->version(),
                'timeZone' => $this->timeZone(),
            ],
            'web_server' => [
                'software'      => $this->software(),
                'php_sapi_name' => $this->php_sapi_name(),
                'detect'        => $this->webServer(),

            ],
        ];
    }

}
