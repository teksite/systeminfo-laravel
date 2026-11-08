<?php

namespace Teksite\SystemInfo\Support;

final class SystemDetector
{
    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    public static function hasProc(): bool
    {
        return @is_readable('/proc/meminfo');
    }

    public static function hasNvidia(): bool
    {
        return SafeExecutor::commandExists('nvidia-smi');
    }
}
