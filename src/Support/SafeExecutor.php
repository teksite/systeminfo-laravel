<?php

namespace Teksite\SystemInfo\Support;


class SafeExecutor
{
    public static function canExec(): bool
    {
        return function_exists('shell_exec')
            && !in_array('shell_exec', explode(',', (string) ini_get('disable_functions')));
    }

    public static function commandExists(string $cmd): bool
    {
        if (!self::canExec()) {
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? "where {$cmd} 2>nul"
            : "command -v {$cmd} 2>/dev/null";

        $out = shell_exec($command);

        return !empty($out);
    }

    public static function exec(string $command): ?string
    {
        if (!self::canExec()) {
            return null;
        }

        $out = @shell_exec($command);

        return $out !== null ? trim($out) : null;
    }

    public static function fileExists(string $path): bool
    {
        return is_readable($path);
    }
}
