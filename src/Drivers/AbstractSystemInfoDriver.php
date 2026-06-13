<?php

namespace Teksite\SystemInfo\Drivers;


use Illuminate\Support\Facades\DB;
use Teksite\SystemInfo\Contracts\SystemInfoDriverInterface;

abstract class AbstractSystemInfoDriver implements SystemInfoDriverInterface
{
    /**
     * Run a shell command safely.
     * Returns null if exec is disabled or the command fails.
     */
    protected function exec(string $command): ?string
    {
        if (!function_exists('shell_exec') || !$this->isExecEnabled()) {
            return null;
        }

        $output = @shell_exec($command . ' 2>/dev/null');

        return ($output !== null && $output !== '') ? trim($output) : null;
    }

    /**
     * Run a shell command safely on Windows (no stderr redirect).
     */
    protected function execWin(string $command): ?string
    {
        if (!function_exists('shell_exec') || !$this->isExecEnabled()) {
            return null;
        }

        $output = @shell_exec($command);

        return ($output !== null && $output !== '') ? trim($output) : null;
    }

    protected function isExecEnabled(): bool
    {
        $disabled = array_map('trim', explode(',', ini_get('disable_functions')));

        return !in_array('shell_exec', $disabled, true);
    }

    protected function formatBytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max(0, $bytes);
        $pow = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
    }

    protected function getPhpModules(): array
    {
        return array_values(array_filter(get_loaded_extensions(), fn($m) => $m !== ''));
    }

    protected function getDatabaseInfo(): array
    {
        try {
            $driver = DB::getDriverName();
            $version = match ($driver) {
                'mysql'  => DB::selectOne('SELECT VERSION() as v')?->v ?? 'unknown',
                'pgsql'  => DB::selectOne('SELECT version() as v')?->v ?? 'unknown',
                'sqlite' => DB::selectOne('SELECT sqlite_version() as v')?->v ?? 'unknown',
                'sqlserver' => DB::selectOne('SELECT @@VERSION AS v')?->v ?? 'unknown',
                default  => 'unknown',
            };

            $dbName = config("database.connections.{$driver}.database", 'unknown');

            return [
                'driver'  => $driver,
                'version' => $version,
                'name'    => $dbName,
            ];
        } catch (\Throwable) {
            return ['driver' => 'unknown', 'version' => 'unknown', 'name' => 'unknown'];
        }
    }

    protected function getWebServerSoftware(): array
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? getenv('SERVER_SOFTWARE') ?? null;

        if ($software) {
            return ['name' => $software, 'source' => 'SERVER_SOFTWARE'];
        }

        // Detect by loaded Apache/Nginx modules or process
        if (function_exists('apache_get_version')) {
            return ['name' => apache_get_version(), 'source' => 'apache_get_version'];
        }

        return ['name' => 'unknown', 'source' => null];
    }

    public function collect(): SystemInfoDTO
    {
        $db = $this->getDatabaseInfo();

        return new SystemInfoDTO(
            laravelVersion: app()->version(),
            phpVersion: PHP_VERSION,
            phpModules: $this->getPhpModules(),
            dbDriver: $db['driver'],
            dbVersion: $db['version'],
            dbName: $db['name'],
            os: $this->getOsInfo(),
            cpu: $this->getCpuInfo(),
            ram: $this->getRamInfo(),
            disks: $this->getDiskInfo(),
            webServer: array_merge($this->getWebServerInfo(), $this->getWebServerSoftware()),
            serverTime: now()->toDateTimeString(),
            appTime: now()->toDateTimeString(),
            appTimezone: config('app.timezone'),
            cacheDriver: config('cache.default'),
            sessionDriver: config('session.driver'),
            environment: config('app.env'),
        );
    }
}
