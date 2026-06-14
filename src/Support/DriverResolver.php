<?php

namespace Teksite\SystemInfo\Support;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Drivers\LinuxDriver;
use Teksite\SystemInfo\Drivers\WindowsDriver;

class DriverResolver
{
    public static function driver(): DriverInterface
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => new WindowsDriver(),
            'Linux'   => new LinuxDriver(),
            default   => throw new \RuntimeException('Unsupported operating system: ' . PHP_OS_FAMILY),
        };
    }
}
