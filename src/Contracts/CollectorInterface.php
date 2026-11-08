<?php

namespace Teksite\SystemInfo\Contracts;

interface CollectorInterface
{
    /** @return array<string, mixed> */
    public function collect(): array;
}
