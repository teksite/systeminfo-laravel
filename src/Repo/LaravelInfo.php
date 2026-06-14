<?php

namespace Teksite\SystemInfo\Repo;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

class LaravelInfo
{
    public function collect(): array
    {
        return [
            'version' => App::version(),

            'environment' => App::environment(),

            'debug' => (bool)Config::get('app.debug'),

            'cache_driver' => Config::get('cache.default'),

            'session_driver' => Config::get('session.driver'),

            'queue_driver' => Config::get('queue.default'),

            'storage_writable' => is_writable(storage_path()),
        ];
    }
}
