<?php

namespace Teksite\SystemInfo\Concerns;

trait Calculates
{
    public function percent(float $used, float $total): float
    {
        if ($total <= 0) return 0;

        return round(($used / $total) * 100, 2);
    }

    public function secondToHumans(null|float $seconds): string|null
    {
        if ($seconds === null) return null;


        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return "{$days}d {$hours}h {$minutes}m";
    }
}
