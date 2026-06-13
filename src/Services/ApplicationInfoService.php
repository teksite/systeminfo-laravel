<?php

namespace Teksite\SystemInfo\Services;

class ApplicationInfoService {
    public function get(): array
    {
        return [
            'framework'=>'Laravel',
            'appTime'=> now()->toDateTimeString(),
            'appTimezone'=> config('app.timezone'),
            'cacheDriver'=> config('cache.default'),
            'sessionDriver'=> config('session.driver'),
            'environment'=> config('app.env'),
        ];
    }
}
