<?php

namespace Teksite\SystemInfo\Support;

/** Tiny, case-insensitive, platform-independent glob matcher (`*` and `?`). */
final class Glob
{
    /** @var array<string, string> */
    private static array $compiled = [];

    public static function match(string $pattern, string $subject): bool
    {
        $regex = self::$compiled[$pattern] ??= '~^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '~')).'$~i';

        return preg_match($regex, $subject) === 1;
    }

    /** @param iterable<string> $patterns */
    public static function matchAny(iterable $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && self::match($pattern, $subject)) return true;
        }

        return false;
    }
}
