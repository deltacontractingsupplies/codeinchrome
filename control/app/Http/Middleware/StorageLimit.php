<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Over the plan's storage (fleet:sync-usage keeps storage_over_at), the
 * operations that add bulk - uploads, unzipping, copying, database imports -
 * are refused with the reason. Editing code, deleting files and everything a
 * site's visitors do carry on: the way back under the limit must stay open.
 */
class StorageLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->storage_over_at) {
            return $next($request);
        }
        // Editing code stays open (the way back under the limit), so a save
        // of a normal size goes through. What adds bulk does not: more than
        // 64 KB in one file write or batch (found in review - batches of new
        // files were a way around the limit).
        if ($request->routeIs('files.store', 'files.batch')) {
            $bytes = $request->routeIs('files.store')
                ? strlen((string) $request->input('content'))
                : array_sum(array_map(fn ($f) => strlen((string) ($f['content'] ?? '')), (array) $request->input('files', [])));
            if ($bytes <= 64 * 1024) {
                return $next($request);
            }
        }
        $gb = $user->planConfig()['storage_gb'] ?? null;

        return response()->json([
            'ok' => false,
            'error' => 'storage_full',
            'hint' => "Your sites use more than the plan's $gb GB. Delete something, or keep uploads on Cloudflare R2 or S3 (see the site's settings page), then try again.",
        ], 507);
    }
}
