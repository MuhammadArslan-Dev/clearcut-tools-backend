<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    /**
     * Sets the app locale from `?locale=` for this request only, so every
     * Model::translate() call downstream (it defaults to app()->getLocale())
     * resolves the right language without controllers/resources having to
     * thread a locale value through manually. An unrecognized locale isn't
     * rejected here — translate()'s own fallback chain (requested ->
     * fallback_locale -> whatever exists) already handles that gracefully.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($locale = $request->query('locale')) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
