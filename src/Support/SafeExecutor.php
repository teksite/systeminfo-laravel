<?php

namespace Teksite\SystemInfo\Support;

/**
 * The only place in the package that spawns processes.
 *
 *  - commands are ALWAYS argv arrays, never shell strings -> no shell, no injection;
 *  - every call has a hard timeout and the child is killed when it is exceeded;
 *  - output goes through a temp file, which (unlike pipes) works with timeouts on Windows too;
 *  - works with `shell_exec` disabled; only `proc_open` is needed, and its absence degrades to null.
 */
final class SafeExecutor
{
    /** @var array<string, ?string> */
    private static array $found = [];

    public static function canExec(): bool
    {
        return function_exists('proc_open');
    }

    /**
     * @param list<string> $command argv, first item is the executable
     * @return ?string trimmed stdout, or null on failure / timeout / empty output
     */
    public static function run(array $command, float $timeout = 3.0, int $maxBytes = 1048576): ?string
    {
        if ($command === [] || !self::canExec()) return null;

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $tmp = @tempnam(sys_get_temp_dir(), 'sysinfo_');

        if ($tmp === false) return null;

        $process = null;

        try {
            $process = @proc_open(
                $command,
                [0 => ['file', $null, 'r'], 1 => ['file', $tmp, 'w'], 2 => ['file', $null, 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );

            if (!is_resource($process)) return null;

            $deadline = microtime(true) + max(0.05, $timeout);

            while (proc_get_status($process)['running']) {
                if (microtime(true) >= $deadline) {
                    @proc_terminate($process, 9);

                    return null;
                }
                usleep(5000);
            }

            $out = @file_get_contents($tmp, false, null, 0, $maxBytes);

            if ($out === false) return null;

            $out = trim($out);

            return $out === '' ? null : $out;
        } finally {
            if (is_resource($process)) {
                @proc_close($process);
            }
            @unlink($tmp);
        }
    }

    /** Locate an executable on PATH without invoking a shell. */
    public static function find(string $command): ?string
    {
        if (!preg_match('/^[A-Za-z0-9._+-]+$/', $command)) return null;

        if (array_key_exists($command, self::$found)) return self::$found[$command];


        $isWindows = PHP_OS_FAMILY === 'Windows';
        $dirs = explode(PATH_SEPARATOR, (string)getenv('PATH'));

        if (!$isWindows) {
            // php-fpm often runs with a stripped PATH.
            array_push($dirs, '/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin');
        }

        $extensions = $isWindows
            ? array_map('strtolower', explode(';', (string)(getenv('PATHEXT') ?: '.EXE;.CMD;.BAT')))
            : [''];

        foreach (array_unique(array_filter($dirs)) as $dir) {
            foreach ($extensions as $ext) {
                $file = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$command.$ext;

                if (@is_file($file) && ($isWindows || @is_executable($file))) {
                    return self::$found[$command] = $file;
                }
            }
        }

        return self::$found[$command] = null;
    }

    public static function commandExists(string $command): bool
    {
        return self::find($command) !== null;
    }

    /** Test helper. */
    public static function reset(): void
    {
        self::$found = [];
    }
}
