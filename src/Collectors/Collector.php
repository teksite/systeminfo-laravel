<?php

namespace Teksite\SystemInfo\Collectors;

use Teksite\SystemInfo\Contracts\CollectorInterface;
use Teksite\SystemInfo\DTOs\ApplicationData;

class Collector implements CollectorInterface
{
    public function collect(): ApplicationData
    {
        return new ApplicationData(
            name: (string) config('app.name'),
            version: app()->version(),
            environment: app()->environment(),
            debug: (bool) config('app.debug'),
            maintenanceMode: app()->isDownForMaintenance(),
            timezone: (string) config('app.timezone'),
            currentTime: now()->toDateTimeString(),
            locale: app()->getLocale(),
            fallbackLocale: config('app.fallback_locale'),
            fakerLocale: config('app.faker_locale'),
            url: config('app.url'),
            assetUrl: config('app.asset_url'),
            cacheDriver: config('cache.default'),
            sessionDriver: config('session.driver'),
            queueDriver: config('queue.default'),
            broadcastDriver: config('broadcasting.default'),
            mailDriver: config('mail.default'),
            filesystemDriver: config('filesystems.default'),
            runningInConsole: app()->runningInConsole(),
            loadedProviders: count(app()->getLoadedProviders()),
        );
    }
}
