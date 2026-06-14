<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Concerns\Calculates;
use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxUptime
{
    use Calculates;
    public function seconds(): ?int
    {
        if (!SafeExecutor::fileExists('/proc/uptime')) {
            return null;
        }

        $content = file_get_contents('/proc/uptime');

        return (int) explode(' ', $content)[0];
    }

    public function human(): ?string
    {
        return $this->secondToHumans($this->seconds());

    }
}
