<?php

namespace Teksite\SystemInfo\Contracts;

interface DriverInterface
{
    public function cpu(): array;

    public function ram(): array;

    public function disk(): array;

    public function gpu(): array;



}
