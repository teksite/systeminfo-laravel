<?php

namespace Teksite\SystemInfo\Repo;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Teksite\SystemInfo\Contracts\CollectorInterface;
use Teksite\SystemInfo\Support\Options;

class DatabaseInfo implements CollectorInterface
{
    public function __construct(private readonly ?string $connectionName = null, private readonly bool $withSize = true) {}

    public static function fromOptions(Options $options): self
    {
        return new self(null, (bool)$options->get('database.size', true));
    }

    public function connection(): string
    {
        return $this->connectionName ?? (string)Config::get('database.default');
    }

    public function driver(): ?string
    {
        $driver = Config::get("database.connections.{$this->connection()}.driver");

        return is_string($driver) ? $driver : null;
    }

    public function database(): ?string
    {
        $database = Config::get("database.connections.{$this->connection()}.database");

        return is_string($database) ? $database : null;
    }

    public function collect(): array
    {
        $db = null;
        $latency = null;

        try {
            $db = DB::connection($this->connectionName);
            $start = hrtime(true);
            $db->select('select 1');
            $latency = round((hrtime(true) - $start) / 1e6, 2);
        } catch (\Throwable) {
            $db = null;
        }

        $version = $db ? $this->version($db) : null;
        $driver = $this->driver();

        if ($driver === 'mysql' && $version !== null && stripos($version, 'mariadb') !== false) {
            $driver = 'mariadb';
        }

        return [
            'connection' => $this->connection(),
            'driver'     => $driver,
            'database'   => $this->database(),
            'connected'  => $db !== null,
            'latency_ms' => $latency,
            'version'    => $version,
            'size'       => $db && $this->withSize ? $this->size($db) : null,
        ];
    }

    /** Backwards compatible alias. */
    public function collector(): array
    {
        return $this->collect();
    }

    protected function version(Connection $db): ?string
    {
        try {
            return match ($db->getDriverName()) {
                'mysql', 'mariadb', 'pgsql' => (string)$db->selectOne('select version() as version')->version,
                'sqlite'                    => (string)$db->selectOne('select sqlite_version() as version')->version,
                'sqlsrv'                    => (string)$db->selectOne("select cast(serverproperty('ProductVersion') as varchar(50)) as version")->version,
                default                     => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    protected function size(Connection $db): ?int
    {
        try {
            return match ($db->getDriverName()) {
                'sqlite'           => $this->sqliteSize($db),
                'mysql', 'mariadb' => (int)($db->selectOne(
                    'select sum(data_length + index_length) as size from information_schema.tables where table_schema = ?',
                    [$db->getDatabaseName()]
                )->size ?? 0),
                'pgsql'            => (int)($db->selectOne('select pg_database_size(current_database()) as size')->size ?? 0),
                'sqlsrv'           => (int)($db->selectOne('select sum(cast(size as bigint)) * 8 * 1024 as size from sys.database_files')->size ?? 0),
                default            => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    private function sqliteSize(Connection $db): ?int
    {
        $path = $db->getDatabaseName();

        return is_string($path) && $path !== ':memory:' && is_file($path) ? (int)filesize($path) : null;
    }
}
