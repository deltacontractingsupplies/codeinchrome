<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Notes when an owner last worked on a site - opened it, edited, ran a
 * command - through any of its routes. A free site with neither work nor
 * visitors for 30 days is paused (sites:idle); this is the "work" half.
 *
 * At most one write an hour per site: the editor makes many requests a
 * minute, and the idle check only needs the day.
 */
class SiteActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $site = $request->route('site');
        if ($site instanceof Site && $site->status === 'live' && $response->isSuccessful()
            && $site->user_id === $request->user()?->getKey()
            && ($site->last_worked_at === null || $site->last_worked_at->lt(now()->subHour()))) {
            Site::whereKey($site->getKey())->update(['last_worked_at' => now(), 'idle_warned_at' => null]);
        }

        return $response;
    }
}
