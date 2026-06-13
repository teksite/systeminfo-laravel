<?php

namespace Teksite\SystemInfo\Concerns;

trait CalculatesPercent
{
    protected function percent(float $used, float $total): float
    {
        if ($total <= 0) return 0;

        return round(($used / $total) * 100, 2);
    }
}
