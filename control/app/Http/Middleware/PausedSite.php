<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A paused site (a trial that ended unpaid) is not worked on: its container is
 * stopped, and building on it would only be deleted with it. Its owner may
 * still take their work away - read files, export the database - or delete
 * it. Everything else answers with the reason and the way out.
 *
 * Only the owner learns that a site is paused. Anyone else passes through to
 * the controller, which refuses them exactly as it would any other site.
 */
class PausedSite
{
    /** Routes that stay open on a paused site: taking the work away, or deleting it. */
    private const OPEN = [
        'dashboard', 'sites.destroy', 'files.index', 'files.download', 'db.export',
        'history.index', 'history.bin', 'sites.backups',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        if (! $site instanceof Site || $site->status !== 'suspended'
            || $site->user_id !== $request->user()?->getKey()
            || in_array($request->route()->getName(), self::OPEN, true)) {
            return $next($request);
        }

        $hint = 'This site is paused because the free trial ended. Upgrade to Starter to bring it back, or download its database from your dashboard first.';
        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json(['ok' => false, 'error' => 'site_paused', 'hint' => $hint], 423);
        }

        return redirect()->route('billing')->with('error', $hint);
    }
}
