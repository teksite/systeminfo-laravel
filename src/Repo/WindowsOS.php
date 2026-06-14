<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Concerns\WindowsPS;

class WindowsOS
{
    use WindowsPS;

    public function family(): string
    {
        return 'Windows';
    }

    public function hostname(): ?string
    {
        return gethostname() ?: null;
    }

    public function timeZone(): string
    {
        $timezone = $this->ps('(Get-TimeZone).Id');

        return $timezone ?: date_default_timezone_get();
    }

    public function version(): array
    {
        $json = $this->ps(
            'Get-CimInstance Win32_OperatingSystem |
             Select Caption,Version,BuildNumber |
             ConvertTo-Json -Compress'
        );

        $os = json_decode($json ?? '', true);

        if (!is_array($os)) {
            return [
                'name'      => 'Unknown Windows',
                'version'   => null,
                'build'     => null,
                'is_server' => false,
            ];
        }

        $caption = $os['Caption'] ?? 'Unknown Windows';

        return [
            'name'    => $caption,
            'version' => $os['Version'] ?? null,
            'build'   => $os['BuildNumber'] ?? null,

            'is_server' => str_contains(
                strtolower($caption),
                'server'
            ),
        ];
    }
}
