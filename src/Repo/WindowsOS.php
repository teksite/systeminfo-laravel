<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxOs
{

    public function family(): string
    {
        return 'Linux';
    }

    public function timeZone(): string
    {
        if (SafeExecutor::fileExists('/etc/timezone')) {
            $timezone = trim(file_get_contents('/etc/timezone'));
        } elseif (SafeExecutor::commandExists('timedatectl')) {
            $timezone = SafeExecutor::exec("timedatectl show --property=Timezone --value") ?? null;
        }

        if (!$timezone) {
            $timezone = date_default_timezone_get();
        }
        return $timezone;
    }

    /**
     * @return array
     */
    public function version(): array
    {
        if (!SafeExecutor::fileExists('/etc/os-release')) return ['name' => 'Unknown', 'version' => null];

        $osRelease = parse_ini_file('/etc/os-release');

        $name = $osRelease['PRETTY_NAME'] ?? $osRelease['NAME'] ?? null;

        $version = $osRelease['VERSION_ID'] ?? null;

        return ['name' => $name, 'version' => $version];


    }
}
