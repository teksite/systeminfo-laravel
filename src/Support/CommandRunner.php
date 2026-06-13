<?php

namespace Teksite\SystemInfo\Support;

class CommandRunner
{
    public function available(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }

        $disabled = explode(
            ',',
            (string) ini_get('disable_functions')
        );

        return !in_array(
            'shell_exec',
            array_map('trim', $disabled),
            true
        );
    }

    public function exists(string $command): bool
    {
        if (!$this->available()) {
            return false;
        }

        $result = @shell_exec(
            "command -v {$command} 2>/dev/null"
        );

        return !empty(trim((string) $result));
    }

    public function run(string $command): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $output = @shell_exec(
            "{$command} 2>/dev/null"
        );

        return $output
            ? trim($output)
            : null;
    }
}
