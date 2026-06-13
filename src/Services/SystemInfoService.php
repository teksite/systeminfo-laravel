<?php

namespace Teksite\SystemInfo\Services;
use RuntimeException;
use Teksite\SystemInfo\Contracts\SystemInfoDriverInterface;
use Teksite\SystemInfo\Drivers\LinuxInfoDriver;
use Teksite\SystemInfo\Drivers\MacOsInfoDriver;
use Teksite\SystemInfo\Drivers\WindowsInfoDriver;
use Teksite\SystemInfo\DTOs\SystemInfoDTO;

class SystemInfoService {

    public function __construct(private ?SystemInfoDriverInterface $driver = null)
    {
        $this->driver = $driver ?? $this->resolveDriver();
    }

    /**
     * Collect all system information and return as DTO.
     */
    public function collect(): SystemInfoDTO
    {
        return $this->driver->collect();
    }

    /**
     * Collect and return as plain array (for JSON responses).
     */
    public function toArray(): array
    {
        return $this->collect()->toArray();
    }

    /**
     * Detect the current OS and return the appropriate driver.
     */
    private function resolveDriver(): SystemInfoDriverInterface
    {
        return match (true) {
            $this->isWindows() => new WindowsInfoDriver(),
            $this->isMacOs()   => new MacOsInfoDriver(),
            $this->isLinux()   => new LinuxInfoDriver(),
            default            => throw new RuntimeException(
                'Unsupported operating system: ' . PHP_OS_FAMILY
            ),
        };
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private function isMacOs(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Return the active driver name for debugging purposes.
     */
    public function getDriverName(): string
    {
        return class_basename($this->driver);
    }
}
