<?php

namespace Teksite\SystemInfo\Repo;

use Teksite\SystemInfo\Support\SafeExecutor;

class LinuxNetwork
{
    public function hostname(): ?string
    {
        return gethostname() ?: null;
    }

    public function localIp(): ?string
    {
        return gethostbyname(gethostname());
    }

    public function publicIp(): ?string
    {
        if (!SafeExecutor::commandExists('curl')) {
            return null;
        }

        return SafeExecutor::exec(
            'curl -s https://api.ipify.org'
        );
    }
}
