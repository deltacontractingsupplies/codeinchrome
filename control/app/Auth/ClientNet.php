<?php

namespace App\Auth;

/**
 * A visitor's address as rate limits and ban signals should see it.
 *
 * IPv6: one person holds a /64 (usually far more), so limits keyed on the
 * full address are no limit at all - the second security audit
 * (2026-09-25) found every per-IP limiter keyed that way.
 */
final class ClientNet
{
    /** The rate-limit key: an IPv4 address, or an IPv6 /64. */
    public static function key(?string $ip): string
    {
        $ip = (string) $ip;
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return $ip;
        }

        return inet_ntop(substr($bin, 0, 8).str_repeat("\0", 8)).'/64';
    }

    /**
     * The network a sign-up came from, as a keyed hash - never the address
     * itself: an IPv4 /24 or an IPv6 /48. Wide on purpose: a banned person
     * returning from the same home or office should match.
     */
    public static function signal(?string $ip): ?string
    {
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return null;
        }
        $net = strlen($bin) === 4 ? substr($bin, 0, 3) : substr($bin, 0, 6);

        return hash_hmac('sha256', 'net:'.bin2hex($net), (string) config('app.key'));
    }
}
