<?php

namespace Teksite\SystemInfo\Concerns;

trait ArrayableData
{
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
