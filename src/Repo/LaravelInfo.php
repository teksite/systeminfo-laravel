<?php

namespace Teksite\SystemInfo\Repo;

use Illuminate\Contracts\Foundation\Application;
use Teksite\SystemInfo\Contracts\CollectorInterface;

class LaravelInfo implements CollectorInterface
{
    public function __construct(private readonly ?Application $app = null) {}

    public function collect(): array
    {
        $app = $this->app ?? app();
        $config = $app['config'];

        return [
            'version'          => $app->version(),
            'environment'      => $app->environment(),
            'debug'            => (bool)$config->get('app.debug'),
            'timezone'         => $config->get('app.timezone'),
            'cache_driver'     => $config->get('cache.default'),
            'session_driver'   => $config->get('session.driver'),
            'queue_driver'     => $config->get('queue.default'),
            'storage_writable' => is_writable($app->storagePath()),
            // The three artisan caches that matter most for request latency.
            'optimizations'    => [
                'config_cached' => self::flag($app, 'configurationIsCached'),
                'routes_cached' => self::flag($app, 'routesAreCached'),
                'events_cached' => self::flag($app, 'eventsAreCached'),
            ],
            'maintenance'      => $app->isDownForMaintenance(),
        ];
    }

    /** Not every method is on the Application contract across Laravel 11-13: guard instead of assuming. */
    private static function flag(object $app, string $method): ?bool
    {
        return method_exists($app, $method) ? (bool)$app->{$method}() : null;
    }
}
