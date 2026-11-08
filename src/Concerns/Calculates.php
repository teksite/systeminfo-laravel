<?php

namespace Teksite\SystemInfo\Concerns;

use Teksite\SystemInfo\Metrics\MetricsCalculator;
use Teksite\SystemInfo\Support\Format;

trait Calculates
{
    public function percent(float|int $used, float|int $total): float
    {
        return MetricsCalculator::percent($used, $total);
    }

    public function secondToHumans(null|int|float $seconds): ?string
    {
        return Format::duration($seconds);
    }
}
