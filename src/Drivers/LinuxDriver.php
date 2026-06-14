<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Repo\LinuxHardware;
use Teksite\SystemInfo\Repo\LinuxOS;

readonly class LinuxDriver implements DriverInterface
{
    public function __construct(protected LinuxHardware $hardware, protected LinuxOS $os) {}

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

    public function collector(): array
    {
        return [
            'hardware' => [
                'cpu'  => $this->cpu(),
                'ram'  => $this->ram(),
                'disk' => $this->disk(),
                'gpu'  => $this->gpu(),
            ],
            'os'       => [
                'family'   => $this->family(),
                'hostname' => $this->hostname(),
                'version'  => $this->version(),
                'timeZone' => $this->timeZone(),
            ],
        ];
    }

}
