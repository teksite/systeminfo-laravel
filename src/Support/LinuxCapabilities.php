<?php

namespace Teksite\SystemInfo\Support;

class LinuxCapabilities
{
    public function __construct(
        protected FileReader    $files,
        protected CommandRunner $commands
    ) {}

    public function toArray(): array
    {
        return [

            'shell_exec' =>
                $this->commands->available(),

            'proc_cpuinfo' =>
                $this->files->exists('/proc/cpuinfo'),

            'proc_meminfo' =>
                $this->files->exists('/proc/meminfo'),

            'proc_stat' =>
                $this->files->exists('/proc/stat'),

            'proc_uptime' =>
                $this->files->exists('/proc/uptime'),

            'os_release' =>
                $this->files->exists('/etc/os-release'),

            'thermal' =>
                $this->files->exists(
                    '/sys/class/thermal/thermal_zone0/temp'
                ),

            'df' =>
                $this->commands->exists('df'),

            'ip' =>
                $this->commands->exists('ip'),

            'free' =>
                $this->commands->exists('free'),

            'lscpu' =>
                $this->commands->exists('lscpu'),

            'lsblk' =>
                $this->commands->exists('lsblk'),

            'hostnamectl' =>
                $this->commands->exists('hostnamectl'),
        ];
    }
}
