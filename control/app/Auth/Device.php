<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * The browser an account was made or used in, as a keyed hash (audit A19).
 *
 * A banned person signing up again from the same browser is the common
 * case, and the network signal misses it the moment they change network.
 * The browser carries a random id in a long-lived cookie - encrypted by
 * Laravel like every cookie, so it cannot be forged or read - and accounts
 * keep only a keyed hash of it. Clearing cookies defeats it; it is one
 * signal among several, and it only ever holds an account for a person to
 * look at, never bans.
 */
final class Device
{
    public const COOKIE = 'cic_device';

    /** The hash for this request's browser, giving it an id if it has none. */
    public static function signal(Request $request): string
    {
        $id = $request->cookie(self::COOKIE);
        if (! is_string($id) || ! preg_match('/^[a-f0-9]{40}$/', $id)) {
            $id = bin2hex(random_bytes(20));
            // Five years, this site only, never readable by scripts.
            Cookie::queue(Cookie::make(self::COOKIE, $id, 60 * 24 * 365 * 5, '/', null, $request->isSecure() || app()->isProduction(), true, false, 'lax'));
            $request->cookies->set(self::COOKIE, $id);
        }

        return hash_hmac('sha256', 'device:'.$id, (string) config('app.key'));
    }

    /** Accounts from the same browser, by either hash they carry. */
    public static function sameBrowser(?string $signal): \Illuminate\Database\Eloquent\Builder
    {
        return \App\Models\User::query()->where(fn ($q) => $q->where('signup_device', $signal)->orWhere('last_device', $signal));
    }
}
