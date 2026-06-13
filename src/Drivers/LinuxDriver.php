<?php

namespace Teksite\SystemInfo\Drivers;

use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Support\CommandRunner;
use Teksite\SystemInfo\Support\FileReader;
use Teksite\SystemInfo\Support\LinuxCapabilities;

class LinuxDriver implements DriverInterface
{
    public function __construct(
        protected FileReader $files,
        protected CommandRunner $commands,
        protected LinuxCapabilities $capabilities,
    ) {}

    public function file(string $path): ?string
    {
        return $this->files->read($path);
    }

    public function command(string $command): ?string
    {
        return $this->commands->run($command);
    }

    public function hasFile(string $path): bool
    {
        return $this->files->exists($path);
    }

    public function hasCommand(string $command): bool
    {
        return $this->commands->exists($command);
    }

    public function capabilities(): array
    {
        return $this->capabilities->toArray();
    }
}
