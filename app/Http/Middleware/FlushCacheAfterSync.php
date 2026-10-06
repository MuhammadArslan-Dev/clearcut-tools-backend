<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * BigQuery sync endpoints write fresh tool/exam data straight to the
 * database. Any response cached with Cache::remember() would keep serving
 * the old data until it expired, so this flushes the cache store after the
 * response has been sent. It only touches the cache store, never config,
 * routes or services. Mirrors the main backend's FlushCacheAfterSync.
 *
 * Dry runs write nothing, so they skip the flush.
 */
class FlushCacheAfterSync
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if ($request->boolean('dry_run')) {
            return;
        }

        Cache::flush();
    }
}
