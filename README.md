# Teksite SystemInfo (Laravel Package)

A lightweight Laravel package for collecting system, hardware, OS, PHP, Laravel, and database information in a
structured and unified way across Linux and Windows systems.

------------------------------------------------------------

## Features

- OS detection (Linux / Windows)
- CPU information (model, cores, threads, usage)
- RAM usage statistics
- Disk usage (multi-disk support)
- GPU detection (NVIDIA support on Linux, Windows adapters)
- Web server detection (Apache, Nginx, LiteSpeed, IIS)
- PHP environment information
- Laravel runtime information
- Database engine detection (MySQL, MariaDB, PostgreSQL, SQLite, SQL Server)
- Network information (hostname, local IP, public IP)
- Uptime tracking
- Timezone detection

------------------------------------------------------------

## Installation

Install via Composer:

composer require teksite/systeminfo-laravel

------------------------------------------------------------

## Usages

### Get Hardware and OS information

```php
use Teksite\SystemInfo\Support\DriverResolver;

$driver = DriverResolver::driver();

$data = $driver->collector();

return $data;
```
#### Example Output

```
[
  "hardware" => [
    "cpu" => [
      "model" => "Intel Core i7 (13th Gen)",
      "cores" => 16,
      "threads" => 24,
      "max_clock_mhz" => 2100,
      "current_clock_mhz" => 2100,
      "usage_percent" => 14.0,
      "architecture" => "x64"
    ],
    "ram" => [
      "total" => 16.0 GB,
      "used" => 12.4 GB,
      "free" => 3.6 GB,
      "percent" => 78.54
    ],
    "disk" => [
      [
        "path" => "C:",
        "total" => 640 GB,
        "used" => 228 GB,
        "free" => 412 GB,
        "percent" => 36.52
      ]
    ],
    "gpu" => [
      "name" => "NVIDIA GeForce GPU"
    ]
  ],

  "network" => [
    "localIp" => "192.168.x.x",
    "publicIp" => "x.x.x.x"
  ],

  "os" => [
    "family" => "Windows",
    "hostname" => "DESKTOP-XXXX",
    "version" => [
      "name" => "Windows",
      "version" => null,
      "build" => null,
      "is_server" => false
    ],
    "timeZone" => "Local Timezone",
    "upTime" => "3d 16h 0m"
  ],

  "web_server" => [
    "software" => "Apache (Win64)",
    "php_sapi_name" => "cgi-fcgi",
    "detect" => [
      "apache" => true,
      "nginx" => false,
      "litespeed" => false,
      "iis" => false,
      "openlitespeed" => false,
      "caddy" => false
    ]
  ],

  "meta" => [
    "timestamp" => 0000000000,
    "datetime" => "YYYY-MM-DD HH:MM:SS"
  ]
]
```


### Get Database information
```php
$data =\Teksite\SystemInfo\Support\DriverResolver::driver()->collector()

return $data;
```
#### Example Output

```
[
"connection" => "mysql"
"driver" => "mysql"
"version" => "8.4.3"
"size" => 475136
]
```


### Get Laravel information
```php
$data =(new \Teksite\SystemInfo\Repo\LaravelInfo())->collect()

return $data;
```
#### Example Output

```
[
  "version" => "13.11.2"
  "environment" => "local"
  "debug" => true
  "cache_driver" => "database"
  "session_driver" => "database"
  "queue_driver" => "database"
  "storage_writable" => true
]
```


### Get PHP information
```php
$data =(new \Teksite\SystemInfo\Repo\PhpInfo())->collect()

return $data;
```
#### Example Output

```
[
  "version" => "8.4.12"
  "sapi" => "cgi-fcgi"
  "thread_safe" => false
  "architecture" => "64-bit"
  "memory_limit" => "512M"
  "max_execution_time" => 36000
  "opcache" => []
  "jit" =>[]
  "loaded_extensions" =>[]
]
```
------------------------------------------------------------

## Requirements

- PHP 8.3+
- Laravel 11+
- shell_exec enabled (optional)

------------------------------------------------------------

## Permissions Notes

Some features may require:

- /proc access (Linux)
- PowerShell access (Windows)
- shell_exec enabled
- Network access for public IP

If a feature is unavailable, it will return null safely.

------------------------------------------------------------

## Architecture

Driver-based system:

- LinuxDriver
- WindowsDriver

Modules:

- Hardware
- OS
- Network
- Web Server
- Runtime info

------------------------------------------------------------

## Roadmap

- PHP extensions analyzer
- Laravel environment inspection
- Database deep metrics
- Optional caching layer

------------------------------------------------------------

## Contributing

- Open issues
- Pull requests welcome

------------------------------------------------------------

## License

MIT License © Teksite

------------------------------------------------------------

## Support

Website: https://teksite.net | https://laratek.net
Email: support@teksite.net
GitHub: https://github.com/teksite/handler
