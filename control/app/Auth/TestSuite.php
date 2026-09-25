<?php

namespace App\Auth;

/**
 * Is this request the platform's own end-to-end suite? Its reserved sign-up
 * domain (config signup.test_domain) is accepted only then: an X-CIC-E2E
 * header holding today's HMAC (UTC; yesterday's too, around midnight) of a
 * secret only the control host and the suite's operator hold. Without it the
 * domain was open to anyone, and skipped Turnstile (the second security
 * audit, 2026-09-25). No secret configured: never.
 */
final class TestSuite
{
    public static function header(string $secret, ?\DateTimeInterface $day = null): string
    {
        return hash_hmac('sha256', ($day ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d'), $secret);
    }

    public static function isRequest(?\Illuminate\Http\Request $request = null): bool
    {
        $secret = (string) config('signup.test_secret');
        $sent = (string) ($request ?? request())->header('X-CIC-E2E', '');
        if ($secret === '' || $sent === '') {
            return false;
        }
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ([$today, $today->modify('-1 day')] as $day) {
            if (hash_equals(self::header($secret, $day), $sent)) {
                return true;
            }
        }

        return false;
    }

    /** A test-domain address, on a request from the suite. */
    public static function address(?string $email): bool
    {
        $test = config('signup.test_domain');

        return $test && $email !== null && str_ends_with(strtolower($email), '@'.strtolower($test)) && self::isRequest();
    }
}
