<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Laravel DISABLES CSRF verification while testing, so no ordinary feature
 * test can tell whether the webhook route is excluded from it. That gap let a
 * production-breaking bug through: the route was still CSRF-protected, and
 * every real Lemon Squeezy delivery would have been rejected with 419 before
 * its signature was ever checked, while the whole webhook suite stayed green.
 *
 * This asserts the CONFIGURATION rather than the behaviour, which is the one
 * thing a test in this environment can actually prove. The end-to-end check -
 * a genuine unsigned POST over HTTP - lives in tests/e2e/specs/security.spec.js.
 */
class CsrfExclusionTest extends TestCase
{
    public function test_the_webhook_path_is_excluded_from_csrf_verification(): void
    {
        $middleware = new ValidateCsrfToken(
            app(), app('encrypter')
        );

        $request = Request::create('/webhooks/lemonsqueezy', 'POST');

        $inExcept = (new \ReflectionClass($middleware))->getMethod('inExceptArray');
        $inExcept->setAccessible(true);

        $this->assertTrue(
            $inExcept->invoke($middleware, $request),
            'webhooks/* is not excluded from CSRF. Real deliveries would be rejected with 419 '
            .'before the signature check runs, and no other test in this suite can see it.'
        );
    }

    public function test_an_ordinary_post_route_is_still_csrf_protected(): void
    {
        $middleware = new ValidateCsrfToken(
            app(), app('encrypter')
        );

        $inExcept = (new \ReflectionClass($middleware))->getMethod('inExceptArray');
        $inExcept->setAccessible(true);

        $this->assertFalse(
            $inExcept->invoke($middleware, Request::create('/sites', 'POST')),
            'The exclusion is too broad: it is covering routes that must stay CSRF-protected.'
        );
    }

    public function test_only_apples_callback_is_excluded_among_the_sign_in_routes(): void
    {
        $middleware = new ValidateCsrfToken(app(), app('encrypter'));
        $inExcept = (new \ReflectionClass($middleware))->getMethod('inExceptArray');
        $inExcept->setAccessible(true);
        $excluded = fn (string $path) => $inExcept->invoke($middleware, Request::create($path, 'POST'));

        // Apple POSTs from its own site and cannot carry our token; its nonce
        // cookie and signed identity token protect that callback instead.
        $this->assertTrue($excluded('/auth/apple/callback'));
        foreach (['/auth/google/callback', '/login', '/register', '/email/verify-code'] as $path) {
            $this->assertFalse($excluded($path), "$path must stay CSRF-protected.");
        }
    }
}
