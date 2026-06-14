<?php

namespace Teksite\SystemInfo\Repo;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class DatabaseInfo
{

    public function connection()
    {
        return Config::get('database.default');

    }

    public function driver()
    {
        $connection = $this->connection();
        return Config::get("database.connections.{$connection}.driver");
    }


    public function database()
    {
        $connection = $this->connection();
        return Config::get("database.connections.{$connection}.database");
    }


    protected function version(): ?string
    {
        try {
            $driver = DB::getDriverName();
            return match ($driver) {
                'mysql', 'pgsql' => DB::selectOne('select version() as version')->version,
                'sqlite'         => DB::selectOne('select sqlite_version() as version')->version,
                'sqlsrv'         => DB::selectOne(" SELECT SERVERPROPERTY('ProductVersion') AS version")->version,
                default          => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    protected function size(): ?int
    {
        try {

            $driver = DB::getDriverName();

            return match ($driver) {

                'sqlite' => $this->sqliteSize(),

                'mysql'  => $this->mysqlSize(),

                'pgsql'  => $this->pgsqlSize(),

                'sqlsrv' => $this->sqlServerSize(),

                default  => null,
            };

        } catch (\Throwable) {
            return null;
        }
    }


    protected function sqliteSize(): ?int
    {
        $path = Config::get(
            'database.connections.sqlite.database'
        );

        return file_exists($path)
            ? filesize($path)
            : null;
    }

    protected function mysqlSize(): ?int
    {
        $db = DB::getDatabaseName();

        $row = DB::selectOne(" SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = ?", [$db]);

        return (int)($row->size ?? 0);
    }

    protected function pgsqlSize(): ?int
    {
        $db = DB::getDatabaseName();

        $row = DB::selectOne('SELECT pg_database_size(?) AS size', [$db]);

        return (int)($row->size ?? 0);
    }

    protected function sqlServerSize(): ?int
    {
        $row = DB::selectOne("SELECT SUM(size) * 8 * 1024 AS size FROM sys.database_files");
        return (int)($row->size ?? 0);
    }


    public function collector(): array
    {
        return [
            'connection' => $this->connection(),
            'driver' => $this->driver(),
            'database' => $this->database(),
            'version'  => $this->version(),
            'size' => $this->size(),
        ];
    }
}
