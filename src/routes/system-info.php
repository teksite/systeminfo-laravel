<?php

use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Teksite\SystemInfo\Http\Controllers\SystemInfoController;
use Teksite\SystemInfo\Http\Middleware\AuthorizeSystemInfo;

$http = (array)config('system-info.http', []);

$throttle = ((int)($http['rate_limit'] ?? 120)) > 0 ? ['throttle:system-info'] : [];

$signed = config('system-info.stream.relative_url', true) ? ValidateSignature::relative() : ValidateSignature::class;

Route::prefix($http['prefix'] ?? 'system-info')->as($http['name'] ?? 'system-info.')->group(function () use ($http, $throttle, $signed): void {
    // Regular endpoints: configurable middleware -> rate limit -> deny-by-default authorization.
    Route::middleware([...($http['middleware'] ?? []), ...$throttle, AuthorizeSystemInfo::class])
         ->group(function (): void {
             Route::get('info', [SystemInfoController::class, 'info'])->name('info');
             Route::get('metrics', [SystemInfoController::class, 'metrics'])->name('metrics');
             Route::get('history', [SystemInfoController::class, 'history'])->name('history');
             Route::get('stream-url', [SystemInfoController::class, 'streamUrl'])->name('stream-url');
         });

    // SSE: authorized by a short-lived signed URL, deliberately outside any session/cookie middleware
    // (a 55 s request must not hold or overwrite the user's session).
    Route::get('stream', [SystemInfoController::class, 'stream'])->middleware([...$throttle, $signed])->name('stream');
});

