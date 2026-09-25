<?php

namespace App\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudflare Turnstile on the forms bots aim at: sign-up, email sign-in and
 * the password-reset mail. Off until both keys are set (TURNSTILE_SITE_KEY,
 * TURNSTILE_SECRET); Google and Apple sign-in need no check of their own.
 *
 * Fails CLOSED: a token Cloudflare cannot confirm is refused. The whole site
 * is served through Cloudflare, so when its verify endpoint is unreachable
 * the forms could not be reached either.
 */
class Turnstile
{
    public const ORIGIN = 'https://challenges.cloudflare.com';

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret'));
    }

    /**
     * The validation rules for a form's token: none while Turnstile is off,
     * and none for the e2e suite's reserved addresses (config signup.test_domain,
     * accepted only where it is set). Those can never receive mail, so such an
     * account stays unverified and able to do nothing unless an operator marks
     * it verified on the server - and without this the suite could not sign up
     * against production at all.
     */
    public static function rules(?string $email = null): array
    {
        $test = config('signup.test_domain');
        if ($test && $email !== null && str_ends_with(strtolower($email), '@'.strtolower($test))) {
            return [];
        }

        return self::enabled() ? ['cf-turnstile-response' => ['required', 'string', 'max:2048', function (string $a, mixed $token, \Closure $fail) {
            if (! self::verify((string) $token, request()->ip())) {
                $fail('Please complete the check that you are not a bot, then try again.');
            }
        }]] : [];
    }

    public static function verify(string $token, ?string $ip): bool
    {
        try {
            $r = Http::asForm()->timeout(10)->post(self::ORIGIN.'/turnstile/v0/siteverify', array_filter([
                'secret' => config('services.turnstile.secret'), 'response' => $token, 'remoteip' => $ip,
            ]));

            return $r->successful() && $r->json('success') === true;
        } catch (\Throwable $e) {
            Log::warning('turnstile verify failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
