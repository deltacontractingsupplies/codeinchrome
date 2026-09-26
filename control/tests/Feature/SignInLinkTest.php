<?php

namespace Tests\Feature;

use App\Fleet\SignInLink;
use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

/** One-time sign-in links (agent sites/signin.go checks them). */
class SignInLinkTest extends TestCase
{
    public function test_the_signature_is_the_one_the_agent_checks(): void
    {
        // The same vector as the agent's TestTheSignatureIsTheOneTheControlPlaneMakes.
        $this->assertSame('3939f0cf14cff11f05c2ea8e0949c0349965d200e0f957b5adfd68af6871a0c6',
            SignInLink::signature('0123456789abcdef0123456789abcdef', 'shop', 7, 'web', '/admin?x=1', 1790000000, 'abcdefghijklmnop'));
    }

    public function test_the_owner_gets_a_link_to_the_site_itself_and_only_to_a_path_on_it(): void
    {
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => str_repeat('s', 64)]]);
        $owner = User::factory()->create();
        $site = Site::create(['user_id' => $owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);

        $r = $this->actingAs($owner)->postJson(route('sites.sign-in-link', $site), ['user' => 3, 'path' => '/admin/orders?page=2'])->assertOk();
        $url = $r->json('url');
        $this->assertStringStartsWith('https://shop.codeinchrome.com/__codeinchrome/sign-in?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['3', 'web', '/admin/orders?page=2'], [$q['u'], $q['g'], $q['p']]);
        $this->assertSame(SignInLink::signature(str_repeat('s', 64), 'shop', 3, 'web', '/admin/orders?page=2', (int) $q['e'], $q['n']), $q['s']);
        $this->assertEqualsWithDelta(now()->addMinutes(10)->getTimestamp(), (int) $q['e'], 5);
        $this->assertSame(32, strlen($q['n']));
        $this->assertDatabaseHas('audit_events', ['action' => 'site.signin_link', 'site' => 'shop']);

        // Never a link that sends the browser somewhere else.
        foreach (['//evil.example/x', 'https://evil.example/', '/\\evil.example', 'relative', '/a b'] as $bad) {
            $this->actingAs($owner)->postJson(route('sites.sign-in-link', $site), ['user' => 3, 'path' => $bad])->assertStatus(422);
        }
        $this->actingAs(User::factory()->create())->postJson(route('sites.sign-in-link', $site), ['user' => 3])->assertNotFound();
    }
}
