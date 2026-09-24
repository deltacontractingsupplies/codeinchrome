<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route a stranger can reach, listed here on purpose. The code is
 * public: a route added without authentication must be a decision, seen in
 * review, never an accident (found 2026-09-24: Laravel's GET/PUT
 * storage/{path}, switched on by default and used by nothing).
 */
class PublicSurfaceTest extends TestCase
{
    private const PUBLIC = [
        'GET|HEAD /', 'GET|HEAD .well-known/security.txt', 'GET|HEAD agent/skill.md', 'GET|HEAD explore',
        'GET|HEAD pricing', 'GET|HEAD terms', 'GET|HEAD privacy', 'GET|HEAD refunds', 'GET|HEAD report', 'POST report',
        'GET|HEAD demos/{demo}/code', 'GET|HEAD up',
        'GET|HEAD login', 'POST login', 'GET|HEAD register', 'POST register',
        'GET|HEAD forgot-password', 'POST forgot-password', 'GET|HEAD reset-password/{token}', 'POST reset-password',
        'GET|HEAD two-factor-challenge', 'POST two-factor-challenge', 'GET|HEAD login/link/{token}',
        'GET|HEAD auth/{provider}/redirect', 'GET|POST|HEAD auth/{provider}/callback',
        // Signed by Lemon Squeezy (LEMONSQUEEZY_WEBHOOK_SECRET), checked in the controller.
        'POST webhooks/lemonsqueezy',
    ];

    public function test_only_the_routes_meant_to_be_public_are_reachable_signed_out(): void
    {
        $public = [];
        foreach (Route::getRoutes() as $route) {
            $middleware = implode(' ', $route->gatherMiddleware());
            if (! preg_match('/\bauth\b|Authenticate/', $middleware)) {
                $public[] = implode('|', $route->methods()).' '.$route->uri();
            }
        }
        sort($public);
        $expected = self::PUBLIC;
        sort($expected);

        $this->assertSame($expected, $public, 'A route reachable without signing in was added or removed: make it deliberate here.');
    }
}
