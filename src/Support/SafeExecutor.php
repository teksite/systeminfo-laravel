<?php

namespace Teksite\SystemInfo\Support;

class SafeExecutor
{
    public static function exec(string $command): ?string
    {
        if (!self::canExec()) {
            return null;
        }

        $output = @shell_exec($command);

        if ($output === null) {
            return null;
        }

        return trim($output);
    }

    public static function canExec(): bool
    {
        return function_exists('shell_exec')
            && !self::isDisabled('shell_exec');
    }

    public static function isDisabled(string $function): bool
    {
        $disabled = ini_get('disable_functions');

        if (!$disabled) return false;

        return in_array($function, array_map('trim', explode(',', $disabled)));
    }

    public static function fileReadable(string $path): bool
    {
        return is_readable($path);
    }

    public static function commandExists(string $command): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return !empty(self::exec("where {$command}"));
        }

        return !empty(self::exec("command -v {$command}"));
    }
}
