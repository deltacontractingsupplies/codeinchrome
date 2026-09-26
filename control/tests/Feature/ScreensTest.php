<?php

namespace Tests\Feature;

use App\Fleet\SignInLink;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A page at every screen size, rendered on another host than the site's. */
class ScreensTest extends TestCase
{
    public function test_the_owner_sees_a_page_at_every_size_rendered_elsewhere(): void
    {
        config(['fleet.hosts' => [
            'h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10],
            'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10],
        ], 'fleet.tokens' => ['h1' => 't1', 'h3' => 't3']]);
        Http::fake(['127.0.0.1:944*/v1/render/shots' => Http::response(['ok' => true, 'shots' => [
            ['name' => 'phone', 'width' => 390, 'height' => 844, 'png' => base64_encode("\x89PNG1")],
            ['name' => 'tablet', 'width' => 820, 'height' => 1180, 'png' => base64_encode("\x89PNG2")],
            ['name' => 'desktop', 'width' => 1440, 'height' => 900, 'png' => base64_encode("\x89PNG3")],
        ]])]);
        $owner = User::factory()->create();
        $site = Site::create(['user_id' => $owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);

        $this->actingAs($owner)->postJson(route('sites.screens', $site), ['path' => '/cart'])->assertOk()
            ->assertJsonPath('url', 'https://shop.codeinchrome.com/cart')->assertJsonCount(3, 'shots')->assertJsonPath('shots.1.width', 820);
        // Rendered by the OTHER host: a page cannot hide from its own host's address.
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'http://127.0.0.1:9443/v1/render/shots') && $r['url'] === 'https://shop.codeinchrome.com/cart');
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'http://127.0.0.1:9441/'));

        foreach (['//evil.example/', 'https://evil.example/', 'cart'] as $bad) {
            $this->actingAs($owner)->postJson(route('sites.screens', $site), ['path' => $bad])->assertStatus(422);
        }
        $this->actingAs(User::factory()->create())->postJson(route('sites.screens', $site), ['path' => '/'])->assertNotFound();
    }

    public function test_behind_the_login_each_size_gets_its_own_sign_in_link(): void
    {
        config(['fleet.hosts' => [
            'h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10],
            'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10],
        ], 'fleet.tokens' => ['h1' => str_repeat('a', 64), 'h3' => str_repeat('b', 64)]]);
        Http::fake(['127.0.0.1:944*/v1/render/shots' => Http::response(['ok' => true, 'shots' => []])]);
        $owner = User::factory()->create();
        $site = Site::create(['user_id' => $owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);

        $this->actingAs($owner)->postJson(route('sites.screens', $site), ['path' => '/admin', 'as' => 2])->assertOk()->assertJsonPath('signedInAs', 2);

        Http::assertSent(function ($r) {
            $urls = $r['urls'] ?? [];
            if (count($urls) !== 3 || count(array_unique($urls)) !== 3) {
                return false;
            }
            foreach ($urls as $u) {
                parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                // Signed with the SITE's host's secret: that agent checks it.
                $ok = str_starts_with($u, 'https://shop.codeinchrome.com/__codeinchrome/sign-in?') && $q['u'] === '2' && $q['p'] === '/admin'
                    && $q['s'] === SignInLink::signature(str_repeat('a', 64), 'shop', 2, 'web', '/admin', (int) $q['e'], $q['n']);
                if (! $ok) {
                    return false;
                }
            }

            return true;
        });
        $this->assertDatabaseHas('audit_events', ['action' => 'site.signin_link', 'site' => 'shop']);
    }
}
