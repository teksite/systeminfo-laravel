<?php

namespace Teksite\SystemInfo\Support;

class SystemDetector {
    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    public static function hasProc(): bool
    {
        return is_readable('/proc/meminfo');
    }

    public static function hasNvidia(): bool
    {
        return SafeExecutor::commandExists('nvidia-smi');
    }

    public static function hasNproc(): bool
    {
        return SafeExecutor::commandExists('nproc');
    }

    public static function hasWmic(): bool
    {
        return SafeExecutor::commandExists('wmic');
    }
}
