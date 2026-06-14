<?php

namespace Teksite\SystemInfo\Concerns;

use Teksite\SystemInfo\Support\SafeExecutor;

trait WindowsPS
{
    public function findPowerShell(): ?string
    {
        $path = SafeExecutor::exec('where powershell');
        if ($path) {
            return trim(explode("\n", $path)[0]);
        }

        $path = SafeExecutor::exec('where pwsh');
        if ($path) {
            return trim(explode("\n", $path)[0]);
        }

        $locations = [
            getenv('WINDIR') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'C:\\Windows\\SysWOW64\\WindowsPowerShell\\v1.0\\powershell.exe',
        ];

        foreach ($locations as $loc) {
            if (file_exists($loc)) {
                return $loc;
            }
        }

        return null;
    }

    private function ps(string $cmd): ?string
    {
        $psPath = $this->findPowerShell();

        if (!$psPath) {
            return null;
        }

        return SafeExecutor::exec(
            $psPath . ' -NoProfile -ExecutionPolicy Bypass -Command "' . $cmd . '"'
        );
    }
}
