<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moves the marker that the home page's cached props are keyed on, so a write is never
 * answered from a copy the browser took before it.
 *
 * Keyed rather than timed. The browser sends back the keys of the once props it already
 * holds and the server skips any prop whose key is in that list, so a key carrying this
 * marker stops matching the moment the ledger moves -- whatever order the user navigates in,
 * and however long ago they last looked. A time window cannot do it: a copy taken a second
 * ago is as out of date as one taken an hour ago, and the server cannot tell them apart.
 *
 * In the cache rather than the session, because it describes the ledger and not the person
 * looking at it, and because it has to outlive the request that moved it.
 *
 * Every write bumps it, whether or not it moved anything the home page shows. A needless
 * bump costs one recomputation on the next visit, where the alternative is a marker that has
 * to know which writes matter.
 */
class ForgetsTheHomeCache
{
    public const KEY = 'home.version';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Not isSuccessful(): a write answers with a redirect, which is 3xx and would read
        // as a failure. Only a 4xx or a 5xx means the write did not happen.
        if ($request->isMethod('GET') || $request->isMethod('HEAD')
            || $response->isClientError() || $response->isServerError()) {
            return $response;
        }

        Cache::increment(self::KEY);

        return $response;
    }

    /**
     * The mark as it stands, for a prop key.
     */
    public static function mark(): string
    {
        return (string) Cache::get(self::KEY, 0);
    }
}
