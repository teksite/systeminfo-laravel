<?php

namespace Teksite\SystemInfo\Repo;

use DateTime;
use Teksite\SystemInfo\Concerns\Calculates;
use Teksite\SystemInfo\Concerns\WindowsPS;
use Teksite\SystemInfo\Support\SafeExecutor;

class WindowsUptime
{
    use WindowsPS , Calculates;

    public function seconds(): ?int
    {
        $boot = $this->ps(
            '(Get-CimInstance Win32_OperatingSystem).LastBootUpTime'
        );

        if (!$boot) {
            return null;
        }

        $bootTime = new DateTime($boot);

        return time() - $bootTime->getTimestamp();
    }

    public function human(): string|float|null
    {
        return $this->secondToHumans($this->seconds());
    }
}
