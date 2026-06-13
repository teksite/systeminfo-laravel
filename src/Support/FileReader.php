<?php

namespace Teksite\SystemInfo\Support;

class FileReader
{
    public function exists(string $path): bool
    {
        return file_exists($path)
            && is_file($path)
            && is_readable($path);
    }

    public function read(string $path): ?string
    {
        if (!$this->exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content !== false
            ? trim($content)
            : null;
    }
}
