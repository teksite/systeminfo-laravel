<?php

namespace Teksite\SystemInfo;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Teksite\SystemInfo\Console\ShowCommand;
use Teksite\SystemInfo\Console\WatchCommand;
use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\Contracts\StoreInterface;

//use Teksite\SystemInfo\Http\Middleware\AuthorizeSystemInfo;
use Teksite\SystemInfo\Metrics\HistoryStore;
use Teksite\SystemInfo\Metrics\MetricsCollector;
use Teksite\SystemInfo\Repo\DatabaseInfo;
use Teksite\SystemInfo\Repo\LaravelInfo;
use Teksite\SystemInfo\Repo\PhpInfo;
use Teksite\SystemInfo\Support\DriverResolver;
use Teksite\SystemInfo\Support\Options;


//use Teksite\SystemInfo\Support\Store\LaravelStore;

class SystemInformationProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigFiles();
        $this->registerSingleton();
        $this->loadAlias();
    }


    public function boot(): void
    {
        $this->bootPublishFiles();
        $this->loadCommands();
        if (!$this->app['config']->get('system-info.http.enabled', true)) return;
        $this->loadRateLimiter();
        $this->loadRoutes();
        //        $this->app->singleton(AuthorizeSystemInfo::class);

    }

    private function registerConfigFiles(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/system-info.php', 'system-info');
    }

    private function registerSingleton(): void
    {

        $this->app->singleton(Options::class, fn($app): Options => new Options((array)$app['config']->get('system-info', [])));

        //        $this->app->singleton(DriverInterface::class, fn ($app): DriverInterface => DriverResolver::driver($app->make(Options::class)));

        //        $this->app->singleton(StoreInterface::class, fn ($app): StoreInterface => new LaravelStore(
        //            $app['cache']->store($app['config']->get('system-info.cache.store')),
        //            (string) $app['config']->get('system-info.cache.prefix', 'system-info:'),
        //        ));
        //
        //        $this->app->singleton(HistoryStore::class, fn ($app): HistoryStore => new HistoryStore(
        //            $app->make(StoreInterface::class),
        //            $app->make(Options::class),
        //        ));
        //
        //        $this->app->singleton(MetricsCollector::class, fn ($app): MetricsCollector => new MetricsCollector(
        //            $app->make(DriverInterface::class),
        //            $app->make(StoreInterface::class),
        //            $app->make(Options::class),
        //            $app->make(HistoryStore::class),
        //        ));
        //
        //        $this->app->singleton(SystemInfoManager::class, fn ($app): SystemInfoManager => new SystemInfoManager(
        //            $app->make(DriverInterface::class),
        //            $app->make(StoreInterface::class),
        //            $app->make(Options::class),
        //            $app->make(MetricsCollector::class),
        //            $app->make(HistoryStore::class),
        //            [
        //                'php'      => static fn (): PhpInfo => new PhpInfo(),
        //                'laravel'  => static fn (): LaravelInfo => new LaravelInfo($app),
        //                'database' => static fn (): DatabaseInfo => DatabaseInfo::fromOptions($app->make(Options::class)),
        //            ],
        //        ));
    }

    private function loadAlias(): void
    {
        $this->app->alias(SystemInfoManager::class, 'system-info');
    }


    private function bootPublishFiles(): void
    {
        $this->publishes([__DIR__.'/../config/system-info.php' => config_path('system-info.php')], ['system-info', 'system-info-config']);
    }


    private function loadCommands(): void
    {
        if ($this->app->runningInConsole()) $this->commands([ShowCommand::class, WatchCommand::class]);
    }

    private function loadRateLimiter(): void
    {
        $limit = (int)$this->app['config']->get('system-info.http.rate_limit', 120);

        if ($limit > 0) {
            RateLimiter::for('system-info', static fn(Request $request): Limit => Limit::perMinute($limit)->by((string)$request->ip()));
        }
    }

    private function loadRoutes(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/system-info.php');
    }
}
