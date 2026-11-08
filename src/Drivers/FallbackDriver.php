<?php

namespace Teksite\SystemInfo\Drivers;

/**
 * Safe default for platforms without a dedicated driver (macOS, BSD, ...). It never throws:
 * it reports what plain PHP can know (load average, disk usage, PHP/Laravel data) and null for
 * the rest, so local development on a Mac does not break the whole package.
 */
class FallbackDriver extends AbstractDriver
{
    public function family(): string
    {
        return PHP_OS_FAMILY;
    }

    public function sample(): array
    {
        $disks = [];

        foreach ($this->configuredDiskPaths() ?? [PHP_OS_FAMILY === 'Windows' ? 'C:\\' : '/'] as $path) {
            if (($usage = $this->diskUsage($path)) !== null) {
                $disks[] = $usage;
            }
        }

        return [
            'time'      => microtime(true),
            'cpu'       => null,
            'load'      => $this->loadAverage(),
            'memory'    => null,
            'network'   => null,
            'disk_io'   => null,
            'disks'     => $disks,
            'container' => null,
            'uptime'    => null,
        ];
    }

    protected function buildBase(): array
    {
        return [
            'cpu'       => [
                'model'         => null,
                'cores'         => 0,
                'threads'       => 0,
                'sockets'       => 1,
                'max_clock_mhz' => null,
                'architecture'  => php_uname('m'),
                'limit_cores'   => null,
            ],
            'gpu'       => [],
            'version'   => [
                'name'         => php_uname('s').' '.php_uname('r'),
                'version'      => php_uname('r'),
                'kernel'       => php_uname('r'),
                'architecture' => php_uname('m'),
            ],
            'timeZone'  => date_default_timezone_get(),
            'boot_time' => null,
        ];
    }
}
