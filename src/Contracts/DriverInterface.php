<?php

namespace Teksite\SystemInfo\Contracts;

interface DriverInterface
{
    // ---- static inventory (cheap to cache) ---------------------------------------------------

    public function family(): string;

    public function hostname(): string;

    public function timeZone(): string;

    /** @return array<string, mixed> */
    public function version(): array;

    /** CPU model / cores / threads / sockets / clock + load average. */
    public function cpu(): array;

    /** @return list<array<string, mixed>> detected GPUs, empty list when none */
    public function gpu(): array;

    /**
     * Everything that does not change between requests, shaped for caching:
     * ['cpu', 'gpu', 'os', 'network', 'boot_time'].
     *
     * @return array<string, mixed>
     */
    public function inventory(bool $fresh = false): array;

    // ---- current usage -----------------------------------------------------------------------

    public function ram(): array;

    public function swap(): array;

    /** @return list<array<string, mixed>> */
    public function disk(): array;

    public function upTime(): ?string;

    public function uptimeSeconds(): ?int;

    /**
     * One raw, cumulative sample of every live counter. Two samples are needed to derive rates
     * (CPU %, network and disk I/O throughput) - see Metrics\MetricsCalculator.
     *
     * @return array<string, mixed>
     */
    public function sample(): array;

    // ---- environment -------------------------------------------------------------------------

    public function software(): ?string;

    public function phpSapiName(): ?string;

    /** @return array<string, bool> */
    public function webServer(): array;

    public function localIp(): ?string;

    /** Performs an outbound HTTPS request; disabled (null) unless enabled in config. */
    public function publicIp(): ?string;

    /** One-shot, uncached dump (backwards compatible with 0.0.x). */
    public function collector(): array;
}
