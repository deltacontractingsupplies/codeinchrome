<?php

namespace App\Fleet;

use App\Models\Site;

/**
 * A one-time sign-in link to a site, as one of its app's users (agent
 * sites/signin.go checks it): the browser opens the site already signed in -
 * an agent cannot type a password, yet must test the pages behind the login.
 *
 * Signed with a key both sides derive from the host's agent secret, bound to
 * the site, the user, the guard, the path and the time: ten minutes, once.
 * The agent's Go tests and SignInLinkTest assert the same vector.
 */
class SignInLink
{
    public const MINUTES = 10;

    /** @return array{url: string, expiresAt: string} */
    public static function make(Site $site, int $user, string $guard = 'web', string $path = '/'): array
    {
        $secret = (string) (config('fleet.tokens')[$site->host] ?? '');
        abort_if($secret === '', 503, 'This site\'s host has no agent secret configured.');
        $expires = now()->addMinutes(self::MINUTES)->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $sig = self::signature($secret, $site->site_id, $user, $guard, $path, $expires, $nonce);

        return [
            'url' => 'https://'.$site->domain.'/__codeinchrome/sign-in?'.http_build_query([
                'u' => $user, 'g' => $guard, 'p' => $path, 'e' => $expires, 'n' => $nonce, 's' => $sig,
            ], '', '&', PHP_QUERY_RFC3986),
            'expiresAt' => date(DATE_ATOM, $expires),
        ];
    }

    public static function signature(string $secret, string $site, int $user, string $guard, string $path, int $expires, string $nonce): string
    {
        $key = hash('sha256', "codeinchrome sign-in link v1\0".$secret, true);

        return hash_hmac('sha256', implode("\n", ['v1', $site, (string) $user, $guard, $path, (string) $expires, $nonce]), $key);
    }
}
