<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Actions that create things - a site, a domain, a subscription - need a
 * verified email address, but ONLY when mail actually leaves the building.
 * Requiring a link that can never arrive would lock every customer out.
 */
class VerifiedWhenMailEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (config('fleet.mail_enabled') && $user && ! $user->hasVerifiedEmail()) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'email_unverified', 'hint' => 'Confirm your email address first.'], 403)
                : redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
