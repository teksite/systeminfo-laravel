<?php

namespace Teksite\SystemInfo\Support;

use Teksite\SystemInfo\Drivers\LinuxDriver;
use Teksite\SystemInfo\Drivers\WindowsDriver;

class DriverResolver {
    public static function driver(): LinuxDriver|WindowsDriver
    {
        return PHP_OS_FAMILY === 'Windows'
            ? new WindowsDriver()
            : new LinuxDriver();
    }
}
