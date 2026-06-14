<?php

namespace Teksite\SystemInfo\Contracts;

interface DriverInterface
{

    public function cpu(): array;

    public function ram(): array;

    public function disk(): array;

    public function gpu(): ?array;

    public function family(): string;

    public function hostname(): string;

    public function timeZone(): string;

    public function version(): array;

    public function software(): ?string;

    public function phpSapiName(): ?string;

    public function webServer(): array;

    public function upTime(): ?string;

    public function localIp(): ?string;

    public function publicIp(): ?string;

    public function collector(): array;

}
