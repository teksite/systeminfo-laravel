<?php

namespace Teksite\SystemInfo\Facades;

use Illuminate\Support\Facades\Facade;
use Teksite\SystemInfo\Contracts\DriverInterface;
use Teksite\SystemInfo\SystemInfoManager;

/**
 * @method static array info(?array $sections = null, bool $fresh = false)
 * @method static array inventory(bool $fresh = false)
 * @method static array metrics(bool $fresh = false)
 * @method static array chart(?string $labels = null, bool $fresh = false)
 * @method static array history(?int $limit = null, ?int $sinceMs = null, ?string $labels = null)
 * @method static ?string publicIp()
 * @method static DriverInterface driver()
 *
 * @see SystemInfoManager
 */
final class SystemInfo extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SystemInfoManager::class;
    }
}
