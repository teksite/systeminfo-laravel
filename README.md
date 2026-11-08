# Teksite SystemInfo for Laravel

Fast, secure server, PHP and Laravel runtime information - plus **real-time metrics shaped for Chart.js**.

- **Inventory**: OS, kernel, CPU (model, sockets, cores, threads), GPU, disks, network interfaces, PHP (OPcache, JIT, Xdebug), Laravel (cache flags, drivers), database (version, size, latency).
- **Live metrics**: CPU % (total / per core / user / system / iowait / steal), load average, RAM, swap, per-disk usage, network and disk I/O throughput, container (cgroup) memory limit, uptime.
- **Chart.js ready**: every chart is `{labels, datasets}`; a live update is the same structure with one point.
- **Fast**: no process is spawned on Linux; Windows spawns PowerShell once per sample; results are cached and shared between concurrent callers.
- **Secure by default**: HTTP access is denied outside `local` until you grant it; argv-only process execution; outbound calls are opt-in.

Linux and Windows are fully supported. Other platforms (macOS, BSD) get a safe fallback driver (load average, disks, PHP/Laravel data) instead of an exception.

## Requirements

PHP 8.3+, Laravel 11 / 12 / 13, `ext-mbstring`. Nothing else: `shell_exec` is **not** needed (only `proc_open`, and only for GPU detection on Linux and for everything on Windows).

## Installation

```bash
composer require teksite/systeminfo-laravel
php artisan vendor:publish --tag=system-info-config   # optional
```

The service provider and the `SystemInfo` facade are auto-discovered.

## Quick start

```php
use Teksite\SystemInfo\Facades\SystemInfo;

SystemInfo::info();                         // full report
SystemInfo::info(['hardware', 'php']);      // only what you need (faster)
SystemInfo::metrics();                      // one live snapshot (array)
SystemInfo::chart();                        // the same, as a Chart.js payload
SystemInfo::history(limit: 60);             // last 60 points, Chart.js payload
```

```bash
php artisan system-info:show --section=hardware
php artisan system-info:watch --interval=2        # live terminal view
php artisan system-info:watch --json --duration=55 # NDJSON, e.g. to feed other tools
```

### Live snapshot

```jsonc
{
  "time": 1731000000123,          // epoch milliseconds
  "interval": 2.004,              // seconds between the two samples used for the rates
  "cpu": { "usage": 12.5, "user": 8.1, "system": 4.0, "iowait": 0.4, "steal": 0.0, "cores": [10.2, 14.8] },
  "load": { "1m": 0.31, "5m": 0.22, "15m": 0.18 },          // null on Windows
  "memory": { "total": 8368123904, "used": 2147483648, "free": 6220640256, "cached": 1073741824, "percent": 25.66 },
  "swap":   { "total": 0, "used": 0, "free": 0, "percent": 0 },
  "disks":  [ { "path": "/", "device": "/dev/vda1", "fs": "ext4", "total": 0, "used": 0, "free": 0, "percent": 0 } ],
  "network": { "rx_bytes_per_sec": 1200.5, "tx_bytes_per_sec": 800.0, "rx_total": 0, "tx_total": 0 },
  "disk_io": { "read_bytes_per_sec": 0.0, "write_bytes_per_sec": 4096.0 },
  "container": null,              // {"memory": {"limit", "used", "percent"}} when a cgroup limit applies
  "uptime": 90061
}
```

Unavailable values are `null`, never a fake `0`. A counter that went backwards (reset, wrap) yields `null` for that point - an honest gap in the chart instead of a spike.

## HTTP API

Routes are registered under `/system-info` (configurable).

| Endpoint | Purpose |
| --- | --- |
| `GET /system-info/metrics` | One live snapshot. `?format=json` (default) or `?format=chartjs`, `&labels=time\|iso\|ms` |
| `GET /system-info/history` | Last N points, Chart.js-shaped. `?points=60`, `?since=<epoch ms>` for incremental polling |
| `GET /system-info/info` | Report. `?only=hardware,os`, `?fresh=1`. Limited to `http.info_sections` |
| `GET /system-info/stream-url` | Short-lived signed URL for the SSE stream |
| `GET /system-info/stream` | Server-Sent Events (signed URL only) |

### Authorisation (read this before deploying)

Outside the `local` environment **every request is rejected (403)** until you configure one of:

```php
// 1. Token (>= 16 chars; shorter tokens are ignored). Sent as "Authorization: Bearer <token>" or "X-System-Info-Token".
// .env
SYSTEM_INFO_TOKEN=put-a-long-random-string-here

// 2. A gate - add 'web' (+ 'auth') to http.middleware so the session user is available.
Gate::define('viewSystemInfo', fn ($user) => $user?->is_admin);

// 3. IP allow-list (CIDR supported): config('system-info.http.allowed_ips')

// 4. Fully custom
\Teksite\SystemInfo\Http\Middleware\AuthorizeSystemInfo::using(fn ($request) => $request->user()?->can('ops'));
```

Tokens are never accepted in the query string (they leak into logs and referrers). Endpoints are rate-limited (120/min per IP by default).

### Chart.js

The payload for every endpoint with `format=chartjs` / `history`:

```jsonc
{
  "meta":   { "points": 150, "from": 1731000000000, "to": 1731000298000, "label_format": "time", "timezone": "UTC" },
  "charts": {
    "cpu":     { "title": "CPU usage", "unit": "%", "suggestedMin": 0, "suggestedMax": 100,
                 "labels": ["12:00:00", "..."], "datasets": [{ "label": "CPU", "data": [12.5, "..."], "borderColor": "#3b82f6", "backgroundColor": "#3b82f633" }] },
    "memory":  { "...": "RAM + Swap, %" },
    "load":    { "...": "1m / 5m / 15m" },
    "network": { "...": "Download / Upload, B/s" },
    "disk_io": { "...": "Read / Write, B/s" }
  }
}
```

**Polling** (simplest, one cheap request per tick, works with any middleware/auth):

```html
<canvas id="cpu"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const headers = { Authorization: 'Bearer ' + TOKEN, Accept: 'application/json' };
const api = (path) => fetch('/system-info/' + path, { headers }).then(r => r.json());

(async () => {
  // 1. draw the recent past instantly
  const { charts } = await api('history?points=60');
  const c = charts.cpu;
  const chart = new Chart(document.getElementById('cpu'), {
    type: 'line',
    data: { labels: c.labels, datasets: c.datasets },
    options: { animation: false, scales: { y: { suggestedMin: c.suggestedMin, suggestedMax: c.suggestedMax } } },
  });

  // 2. then append one point every 2 seconds
  setInterval(async () => {
    const { charts } = await api('metrics?format=chartjs');
    const p = charts.cpu;
    chart.data.labels.push(p.labels[0]);
    p.datasets.forEach((d, i) => chart.data.datasets[i].data.push(d.data[0]));
    if (chart.data.labels.length > 60) {
      chart.data.labels.shift();
      chart.data.datasets.forEach(d => d.data.shift());
    }
    chart.update('none');
  }, 2000);
})();
</script>
```

**Server-Sent Events** (push; `EventSource` cannot send headers, hence the signed URL):

```js
async function connect() {
  const { url } = await fetch('/system-info/stream-url?interval=2&format=chartjs', { headers }).then(r => r.json());
  const es = new EventSource(url);
  es.addEventListener('metrics', (e) => {
    const { charts } = JSON.parse(e.data);   // same shape as polling
    /* append points as above */
  });
  es.onerror = () => { es.close(); setTimeout(connect, 1000); }; // URL expired / network drop -> ask for a new one
}
connect();
```

> **SSE trade-off.** Each open stream holds one PHP worker for up to `stream.max_duration` (55 s), then ends cleanly so the browser reconnects. With a small PHP-FPM pool, or under Octane/Swoole, prefer polling. Many viewers share the same sample either way (see below).

For a time axis use `?labels=ms` (epoch milliseconds) with a Chart.js date adapter, or `?labels=iso`.

## How it stays fast

| Concern | Approach |
| --- | --- |
| CPU %, network, disk I/O are *rates* | The previous raw sample lives in the cache. A request takes **one** new sample and diffs it - no `sleep()`. Only a cold cache pays one short (`250 ms`) window. |
| 50 dashboards polling | Callers within `live.min_interval` share one snapshot; computation is behind a lock with double-checked caching (no stampede). |
| Static data (CPU model, OS, GPU, ...) | Cached for `cache.inventory_ttl` (1 h). |
| Linux | `/proc` + `/sys` only. No fork/exec. A sample takes ~0.1 ms. |
| Windows | One PowerShell call per sample / one for the whole inventory (not ~10 per call). |
| History | Bounded ring buffer (`history.points`, default 300), written at most every `history.resolution` seconds. |
| Database size | Cached for `cache.database_ttl` (can be a heavy `information_schema` query). |

Use a **shared cache store** (Redis, Memcached, database, file). With the `array` store the previous sample cannot survive between requests, so each request pays the sampling window.

Windows note: a PowerShell start costs several hundred milliseconds. Keep polling intervals at 2-5 s there; Linux handles 1 s comfortably.

## Security model

- **Deny by default** outside `local`; see Authorisation.
- **No shell.** Processes are launched from argv arrays (`proc_open`, no `/bin/sh`), with a hard timeout and kill. PowerShell scripts go through `-EncodedCommand`. No user input ever reaches a command.
- **No hidden outbound traffic.** Public IP lookup (third-party request) is **off** by default; when enabled it is HTTPS-only, has a timeout, never follows redirects and is cached.
- **Minimal exposure.** The `info` endpoint only reveals `http.info_sections` (`hardware`, `os`, `php`, `laravel` by default). Network addresses, web-server banner and database name are opt-in.
- **SSE without cookies.** The stream route authenticates with a signed, expiring URL and never starts a session, so a long request cannot overwrite session data.
- Tokens are compared with `hash_equals`, are never read from the URL, and must be >= 16 characters.

## Configuration

Publish `config/system-info.php`; every key is documented inline and every key is optional (nested options are deep-merged with defaults). Notable ones:

| Key | Default | Meaning |
| --- | --- | --- |
| `http.enabled` | `true` | Register the routes at all |
| `http.token` / `allowed_ips` / `allow_local` | `null` / `[]` / `true` | Access control |
| `http.middleware` | `[]` | Extra middleware, e.g. `['web', 'auth']` |
| `live.min_interval` | `1.0` | Seconds during which callers share a snapshot |
| `history.points` x `history.resolution` | `300` x `2.0` | 10 minutes of history |
| `disk.paths` | `null` | `null` = auto-detect local filesystems; or list paths explicitly |
| `network.exclude_interfaces` | docker/veth/lo/... | Globs ignored for throughput and local IP |
| `network.public_ip.enabled` | `false` | Opt-in outbound lookup |
| `cache.store` | default store | Use a shared store in production |

### Containers

Inside Docker/Kubernetes `/proc` still reports the **host**. The package therefore also reads the cgroup limit (v1 and v2): `container.memory` is the number that matters for OOM, and `hardware.cpu.limit_cores` shows the CPU quota. CPU % is host-wide.

## Programmatic use of drivers

```php
use Teksite\SystemInfo\Support\DriverResolver;

$driver = DriverResolver::driver();
$driver->collector();   // one-shot, uncached dump (same shape as 0.0.x)
$driver->sample();      // raw cumulative counters
```

`Repo\PhpInfo`, `Repo\LaravelInfo` and `Repo\DatabaseInfo` keep their `collect()` methods.

## Upgrading from 0.0.x

- `disk` is now always a **list** of mounts and `gpu` a **list** of GPUs.
- `Repo\Linux*`, `Repo\Windows*`, `Concerns\WindowsPS` and `SafeExecutor::exec()` were removed; use the drivers.
- Public IP is no longer fetched unless `network.public_ip.enabled` is `true`.
- Publish tag: `system-info-config` (`system-info` still works).
- `composer.json` no longer sets `minimum-stability: dev`.

Full list in [CHANGELOG.md](CHANGELOG.md).

## Limitations

- Windows GPU memory is under-reported above 4 GB (a Windows API limit); per-core CPU is not available on Windows; no load average.
- GPU *live* utilisation is not collected (inventory only).
- Inside containers, CPU % and network counters reflect the host unless the runtime isolates `/proc`.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The core (drivers, calculator, history, chart formatter) is framework-independent and unit-tested against fixture `/proc` trees and a faked PowerShell; HTTP behaviour is covered with Orchestra Testbench.

## Roadmap

Prometheus exposition endpoint, threshold alerts (webhook / notification), macOS driver, per-process top-N (opt-in), queue / Horizon health.

## License

MIT (c) Teksite - support@teksite.net - https://teksite.net
