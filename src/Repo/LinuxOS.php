<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxOS
{


    public function family(): string
    {
        return 'Linux';
    }

    public function hostname(): ?string
    {
        return gethostname() ?: null;
    }

    public function kernel(): string
    {
        return php_uname('r');
    }

    public function timeZone(): string
    {
        $timezone = null;

        if (SafeExecutor::fileExists('/etc/timezone')) {
            $timezone = trim(
                file_get_contents('/etc/timezone')
            );
        } elseif (SafeExecutor::commandExists('timedatectl')) {
            $timezone = SafeExecutor::exec(
                'timedatectl show --property=Timezone --value'
            );
        }

        return $timezone ?: date_default_timezone_get();
    }

    public function version(): array
    {
        if (!SafeExecutor::fileExists('/etc/os-release')) return ['name' => 'Unknown', 'version' => null,];

        $osRelease = parse_ini_file('/etc/os-release');

        if (!is_array($osRelease)) return ['name' => 'Unknown', 'version' => null,];

        return [
            'name' => $osRelease['PRETTY_NAME'] ?? $osRelease['NAME'] ?? 'Unknown',
            'version' => $osRelease['VERSION_ID'] ?? null,
        ];
    }
}
