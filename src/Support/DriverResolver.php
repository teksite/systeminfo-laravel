<?php

namespace Teksite\SystemInfo\Support;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Drivers\FallbackDriver;
use Teksite\SystemInfo\Drivers\LinuxDriver;
use Teksite\SystemInfo\Drivers\WindowsDriver;

final class DriverResolver
{
    public static function driver(Options|array|null $options = null): DriverInterface
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => new WindowsDriver($options),
            'Linux'   => new LinuxDriver($options),
            default   => new FallbackDriver($options),
        };
    }
}
