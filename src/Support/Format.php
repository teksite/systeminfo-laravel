<?php

declare(strict_types=1);

namespace Teksite\SystemInfo\Support;

final class Format
{
    public static function bytes(null|int|float $bytes, int $precision = 1): string
    {
        if ($bytes === null) return 'n/a';

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = max(0.0, (float)$bytes);
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string)(int)$value : number_format($value, $precision, '.', '')).' '.$units[$i];
    }

    public static function rate(null|int|float $bytesPerSecond): string
    {
        return $bytesPerSecond === null ? 'n/a' : self::bytes($bytesPerSecond).'/s';
    }

    public static function percent(null|int|float $value): string
    {
        return $value === null ? 'n/a' : number_format((float)$value, 1, '.', '').'%';
    }

    public static function duration(null|int|float $seconds): ?string
    {
        if ($seconds === null) return null;

        $seconds = max(0, (int)$seconds);

        return intdiv($seconds, 86400).'d '.intdiv($seconds % 86400, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }
}
