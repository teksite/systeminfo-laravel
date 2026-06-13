<?php

namespace Teksite\SystemInfo\DTOs;

readonly class ApplicationData
{
    public function __construct(
        public string $name,
        public string $version,
        public string $environment,
        public bool $debug,
        public bool $maintenanceMode,
        public string $timezone,
        public string $currentTime,
        public string $locale,
        public string $fallbackLocale,
        public ?string $fakerLocale,
        public ?string $url,
        public ?string $assetUrl,
        public string $cacheDriver,
        public string $sessionDriver,
        public string $queueDriver,
        public string $broadcastDriver,
        public string $mailDriver,
        public string $filesystemDriver,
        public bool $runningInConsole,
        public int $loadedProviders,
    ) {}
}
