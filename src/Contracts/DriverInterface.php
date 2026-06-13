<?php

namespace Teksite\SystemInfo\Contracts;

interface DriverInterface
{
    public function file(string $path): ?string;

    public function command(string $command): ?string;

    public function hasFile(string $path): bool;

    public function hasCommand(string $command): bool;

    public function capabilities(): array;
}
