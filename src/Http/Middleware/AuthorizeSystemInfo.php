<?php

namespace Teksite\SystemInfo\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Teksite\SystemInfo\Support\Options;

/**
 * Deny-by-default access control for every endpoint that exposes server data.
 *
 * Access is granted when ANY of these is true (first match wins):
 *  1. a callback registered with AuthorizeSystemInfo::using() returns true (and then it alone decides);
 *  2. a valid token is sent in `Authorization: Bearer ...` or `X-System-Info-Token` (never in the URL:
 *     query strings end up in logs and referrers);
 *  3. the `viewSystemInfo` gate exists and allows the current user;
 *  4. the client IP is in `http.allowed_ips` (CIDR ranges supported);
 *  5. the app runs in the `local` environment and `http.allow_local` is on.
 */
final class AuthorizeSystemInfo
{
    private const int MIN_TOKEN_LENGTH = 16;

    private static ?Closure $callback = null;

    public function __construct(private readonly Options $options) {}

    /** @param Closure(Request): bool $callback */
    public static function using(Closure $callback): void
    {
        self::$callback = $callback;
    }

    public static function flush(): void
    {
        self::$callback = null;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->authorized($request)) abort(403, 'Forbidden.');

        return $next($request);
    }

    private function authorized(Request $request): bool
    {
        if (self::$callback !== null) return (bool)(self::$callback)($request);

        $token = $this->options->get('http.token');

        // A short token is a footgun, not a secret: ignore it rather than pretend it protects anything.
        if (is_string($token) && strlen($token) >= self::MIN_TOKEN_LENGTH) {
            $provided = $request->bearerToken() ?? $request->header('X-System-Info-Token');

            if (is_string($provided) && $provided !== '' && hash_equals($token, $provided)) return true;
        }

        if (Gate::has('viewSystemInfo') && Gate::forUser($request->user())->allows('viewSystemInfo'))  return true;

        $allowed = array_values(array_filter((array)$this->options->get('http.allowed_ips', []), 'is_string'));

        if ($allowed !== [] && ($ip = $request->ip()) !== null && IpUtils::checkIp($ip, $allowed)) return true;

        return (bool)$this->options->get('http.allow_local', true) && app()->environment('local');
    }
}
