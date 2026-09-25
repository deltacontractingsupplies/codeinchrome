<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A paused site (a trial that ended unpaid, idle for 30 days, or stopped by
 * the abuse checks) is not worked on: its container is
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
        'history.index', 'history.bin', 'sites.backups', 'sites.wake',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->route('site');
        // A site paused by the abuse checks cannot be deleted by its owner:
        // deleting it freed the plan's slot for a fresh one at once (the
        // second security audit, 2026-09-25). Its work can still be taken away.
        $abusePaused = $site instanceof Site && in_array($site->paused_reason, ['cpu', 'egress', 'abuse'], true);
        if (! $site instanceof Site || $site->status !== 'suspended'
            || $site->user_id !== $request->user()?->getKey()
            || (in_array($request->route()->getName(), self::OPEN, true) && ! ($abusePaused && $request->route()->getName() === 'sites.destroy'))) {
            return $next($request);
        }

        $hint = match ($site->paused_reason) {
            'idle' => 'This site was paused after 30 days with no visitors and no edits. Bring it back with one click on your dashboard.',
            'cpu', 'egress', 'abuse' => 'This site is paused by our abuse checks. Write to support if you think that is a mistake.',
            default => 'This site is paused because the free trial ended. Upgrade to Starter to bring it back, or download its database from your dashboard first.',
        };
        if ($request->expectsJson() || ! $request->isMethod('GET')) {
            return response()->json(['ok' => false, 'error' => 'site_paused', 'reason' => $site->paused_reason, 'hint' => $hint], 423);
        }

        return redirect()->route($site->paused_reason === 'idle' ? 'dashboard' : 'billing')->with('error', $hint);
    }
}
