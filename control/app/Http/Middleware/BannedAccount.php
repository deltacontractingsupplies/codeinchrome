<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** A banned account (App\Abuse\Enforcer) is signed out on its next request. */
class BannedAccount
{
    public const MESSAGE = 'This account has been closed for breaking the terms of use, and its sites taken down.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->banned_at) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => 'account_closed', 'hint' => self::MESSAGE], 403)
                : redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
        }

        return $next($request);
    }
}
