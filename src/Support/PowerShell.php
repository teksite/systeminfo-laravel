<?php

namespace Teksite\SystemInfo\Support;

/**
 * Runs PowerShell scripts through -EncodedCommand (UTF-16LE base64): no quoting, no
 * shell-escaping and no injection surface, whatever the script contains.
 */
class PowerShell
{
    private static ?string $binary = null;

    private static bool $resolved = false;

    public function __construct(private readonly float $timeout = 15.0) {}

    public function binary(): ?string
    {
        if (self::$resolved) return self::$binary;

        self::$resolved = true;

        $root = getenv('SystemRoot') ?: getenv('WINDIR') ?: 'C:\\Windows';
        $classic = $root.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';

        if (@is_file($classic)) return self::$binary = $classic;

        return self::$binary = SafeExecutor::find('powershell') ?? SafeExecutor::find('pwsh');
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    /** Run a script and decode its JSON output.
     *
     * @throws \JsonException
     */
    public function json(string $script): ?array
    {
        $out = $this->run($script);

        if ($out === null) return null;

        $decoded = json_decode(ltrim($out, "\xEF\xBB\xBF"), true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    public function run(string $script): ?string
    {
        $binary = $this->binary();

        if ($binary === null) return null;

        $prefix = '$ProgressPreference="SilentlyContinue";$ErrorActionPreference="SilentlyContinue";'
            .'[Console]::OutputEncoding=[System.Text.Encoding]::UTF8;';

        $encoded = base64_encode((string)mb_convert_encoding($prefix.$script, 'UTF-16LE', 'UTF-8'));

        return SafeExecutor::run(
            [$binary, '-NoLogo', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded],
            $this->timeout
        );
    }
}
