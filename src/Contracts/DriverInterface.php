<?php

namespace Teksite\SystemInfo\Contracts;

interface DriverInterface
{
    public function cpu(): array;

    public function ram(): array;

    public function disk(): array;

    public function gpu(): array;

    public function family(): string;

    public function hostname(): string;

    public function timeZone(): string;

    public function version(): array;

    public function software();

    public function collector(): array;


}
