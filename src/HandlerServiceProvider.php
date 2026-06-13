<?php

namespace Teksite\SystemInfo;

use Illuminate\Support\ServiceProvider;
use Teksite\Handler\Services\Builder\ResponderServices;

class SystemInformationProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigFiles();
    }


    public function boot(): void
    {
        $this->bootPublishFiles();
    }

    private function registerConfigFiles(): void
    {
        $configPath = config_path('system-info.php');

        $this->mergeConfigFrom(
            file_exists($configPath) ? $configPath : __DIR__ . '/config/system-info.php', 'system-info');

    }


    private function bootPublishFiles(): void
    {
        $this->publishes([
            __DIR__ . '/config/system-info.php' => config_path('system-info.php')
        ], 'system-info');
    }

}
