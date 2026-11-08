# Changelog

## 1.0.0

Complete rewrite - see README "Upgrading from 0.0.x".

### Added
- Real-time metrics: CPU % (total, per core, user/system/iowait/steal), load average, RAM, swap, disks,
  network and disk I/O throughput, container (cgroup) memory limits.
- Chart.js-shaped output (`?format=chartjs`), ring-buffer history, polling, and Server-Sent Events via signed URLs.
- Deny-by-default HTTP authorisation (token / gate / IP allow-list / custom callback), rate limiting.
- `SystemInfo` facade, `system-info:show` and `system-info:watch` Artisan commands.
- `FallbackDriver` so unsupported platforms (macOS...) degrade instead of throwing.
- Real multi-disk support on Linux, multi-socket CPU detection, GPU list (nvidia-smi / lspci).
- Unit and feature tests, CI matrix (Linux + Windows, PHP 8.3/8.4, Laravel 11-13).

### Changed (performance)
- Linux: no process is spawned on the hot path; everything comes from `/proc` and `/sys`.
- Windows: PowerShell is invoked once per live sample / once for the whole inventory (was ~10 times per call,
  each preceded by a `where powershell` lookup).
- Inventory data cached; live samples shared between concurrent callers behind a lock.
- Disk usage computed from a single `statvfs` result (was 5 calls per disk).

### Fixed
- Provider imported a class that does not exist (`Teksite\Handler\...`) and merged the app's own config into itself.
- `composer.json` shipped `minimum-stability: dev`.
- Division by zero / `TypeError` when `disk_total_space()` or `/proc/meminfo` is unavailable.
- CPU cores/threads wrong on multi-socket machines; no model on ARM.
- `parse_ini_file()` breaking on valid `/etc/os-release` files.
- `openlitespeed` also reported as `litespeed`.
- Windows: multi-CPU hosts returned null data (JSON array vs object), `json_decode(null)` deprecation,
  locale-dependent `LastBootUpTime` parsing, mixed RAM total/free sources, non-fixed drives listed.
- `gethostbyname(gethostname())` (blocking DNS, usually `127.0.1.1`) replaced by `net_get_interfaces()`.
- README documented `DriverResolver::driver()->collector()` as returning database info; it never did.

### Security
- No shell strings anywhere: argv-only `proc_open` with hard timeouts; PowerShell via `-EncodedCommand`;
  `-ExecutionPolicy Bypass` removed.
- Public IP lookup (outbound request to a third party, previously unconditional and without a timeout) is opt-in,
  HTTPS-only, time-limited and cached.
- Sensitive sections (network, web server, database) are not exposed over HTTP unless explicitly allowed.

### Breaking
- `disk` is always a list of mounts; `gpu` is a list; Linux `disk` was a single object.
- `Repo\Linux*`, `Repo\Windows*`, `Concerns\WindowsPS` and `SafeExecutor::exec()` were removed.
- Publish tag is `system-info-config` (the old `system-info` tag still works).
