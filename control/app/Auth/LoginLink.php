<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A one-time sign-in link, issued ONLY from the server's command line
 * (php artisan user:login-link). It exists so a browser-driving agent can be
 * handed a session without a password being typed anywhere - there is no web
 * route that issues one.
 *
 * The token is 64 random characters; only its SHA-256 is stored, it lives a
 * few minutes, and the first use consumes it. An account with two-factor
 * turned on is refused: a link must never be a way around 2FA.
 */
class LoginLink
{
    private const PREFIX = 'auth.login-link.';

    public static function issue(User $user, int $minutes = 5): string
    {
        if ($user->two_factor_confirmed_at) {
            throw new \RuntimeException('This account has two-factor authentication on; a sign-in link would bypass it.');
        }
        $token = Str::random(64);
        Cache::put(self::PREFIX.hash('sha256', $token), $user->id, now()->addMinutes(max(1, min($minutes, 30))));

        return route('login.link', $token);
    }

    /** The user the token belongs to, consuming it; null if unknown, used or expired. */
    public static function consume(string $token): ?User
    {
        if (strlen($token) !== 64) {
            return null;
        }
        $id = Cache::pull(self::PREFIX.hash('sha256', $token));
        $user = $id ? User::find($id) : null;

        return $user && ! $user->two_factor_confirmed_at ? $user : null;
    }
}
