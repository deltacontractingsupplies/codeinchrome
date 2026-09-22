<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'],
            'fleet.zone' => 'codeinchrome.com',
        ]);

        Http::fake([
            // Method-aware: Cloudflare's list endpoint returns an array and
            // its create endpoint returns an object with an id. A fake that
            // returned the same empty result for both made upsert() fail on a
            // missing id, which is a fault in the fake, not in the code.
            'api.cloudflare.com/*' => fn ($r) => Http::response([
                'success' => true,
                'errors' => [],
                'result' => $r->method() === 'GET' ? [] : ['id' => 'rec-test'],
            ]),
            // From config, never a literal: a hardcoded version silently goes
            // stale the moment fleet.min_agent_version is raised.
            '127.0.0.1:944*/v1/host' => Http::response([
                'ok' => true, 'version' => config('fleet.min_agent_version'), 'sites' => 0, 'running' => 0,
            ]),
            '127.0.0.1:944*/v1/sites*' => fn ($r) => $r->method() === 'DELETE'
                ? Http::response(['ok' => true, 'parts' => ['container' => 'removed', 'vhost' => 'removed', 'data' => 'removed', 'log' => 'removed', 'network' => 'removed']])
                : Http::response(['ok' => true, 'site' => ['id' => 'x', 'port' => 20000, 'state' => 'running']], 201),
        ]);
    }

    public function test_the_landing_page_lists_the_plans(): void
    {
        $this->get('/')->assertOk()->assertSee('Starter')->assertSee('Studio');
    }

    public function test_a_visitor_can_register_and_lands_on_the_dashboard(): void
    {
        $this->post('/register', [
            'name' => 'Test Owner',
            'email' => 'new@example.com',
            'password' => 'correct-horse-battery-staple-92',
            'password_confirmation' => 'correct-horse-battery-staple-92',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertSame('free', User::where('email', 'new@example.com')->first()->plan);
    }

    public function test_registration_rejects_a_known_breached_password(): void
    {
        // Credential stuffing beats complexity rules, so the breach corpus
        // check matters more than symbol requirements.
        $this->post('/register', [
            'name' => 'Test Owner',
            'email' => 'weak@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
        $this->assertNull(User::where('email', 'weak@example.com')->first());
    }

    public function test_a_wrong_password_and_an_unknown_email_give_the_same_answer(): void
    {
        User::factory()->create(['email' => 'real@example.com', 'password' => bcrypt('a-real-password-1')]);

        $wrongPassword = $this->post('/login', ['email' => 'real@example.com', 'password' => 'nope'])
            ->assertSessionHasErrors('email');
        $unknownEmail = $this->post('/login', ['email' => 'ghost@example.com', 'password' => 'nope'])
            ->assertSessionHasErrors('email');

        // Different messages would tell an attacker which addresses have accounts.
        $this->assertSame(
            session('errors')->get('email'),
            $unknownEmail->getSession()->get('errors')->get('email'),
        );
        $this->assertGuest();
    }

    public function test_the_dashboard_requires_signing_in(): void
    {
        $this->get('/sites')->assertRedirect(route('login'));
        $this->post('/sites', ['site_id' => 'anything'])->assertRedirect(route('login'));
        $this->assertSame(0, Site::count());
    }

    public function test_a_user_can_create_a_site_from_the_dashboard(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);

        $this->actingAs($user)->post('/sites', ['site_id' => 'my-shop'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('sites', ['site_id' => 'my-shop', 'user_id' => $user->id, 'status' => 'live']);
    }

    public function test_a_name_is_normalised_rather_than_rejected_for_case(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);

        $this->actingAs($user)->post('/sites', ['site_id' => '  My-Shop  ']);

        $this->assertDatabaseHas('sites', ['site_id' => 'my-shop']);
    }

    public function test_a_bad_name_comes_back_with_a_message_the_customer_can_act_on(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);

        $this->actingAs($user)->post('/sites', ['site_id' => 'www'])
            ->assertSessionHasErrors('site_id');

        $this->assertSame(0, Site::count());
    }

    public function test_a_user_cannot_see_another_users_sites(): void
    {
        $mine = User::factory()->create(['plan' => 'starter']);
        $theirs = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($theirs)->post('/sites', ['site_id' => 'their-shop']);

        // A fresh session, because two customers do not share one. Without
        // this the other user's flash message survives into the next request
        // and the assertion fails on a test artefact rather than a leak.
        $this->flushSession();

        $response = $this->actingAs($mine)->get('/sites');

        $response->assertOk()->assertDontSee('their-shop');
        // Asserted on the data as well as the HTML: a view that happened not
        // to render the name would still be handing it to the template.
        $this->assertSame([], $response->viewData('sites')->pluck('site_id')->all());
    }

    public function test_a_user_cannot_delete_another_users_site(): void
    {
        $mine = User::factory()->create(['plan' => 'starter']);
        $theirs = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($theirs)->post('/sites', ['site_id' => 'not-yours']);
        $site = Site::where('site_id', 'not-yours')->firstOrFail();

        $this->actingAs($mine)->delete(route('sites.destroy', $site))->assertNotFound();

        $this->assertDatabaseHas('sites', ['site_id' => 'not-yours']);
    }

    public function test_a_user_can_delete_their_own_site(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($user)->post('/sites', ['site_id' => 'going-away']);
        $site = Site::where('site_id', 'going-away')->firstOrFail();

        $this->actingAs($user)->delete(route('sites.destroy', $site))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('sites', ['site_id' => 'going-away']);
    }

    public function test_signing_out_clears_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_the_owner_can_open_the_editor(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($user)->post('/sites', ['site_id' => 'editable']);
        $site = Site::where('site_id', 'editable')->firstOrFail();

        $this->actingAs($user)->get(route('sites.edit', $site))
            ->assertOk()
            ->assertSee('data-site="editable"', false)
            ->assertSee('csrf-token', false);
    }

    public function test_a_stranger_cannot_open_someone_elses_editor(): void
    {
        $owner = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($owner)->post('/sites', ['site_id' => 'private-one']);
        $site = Site::where('site_id', 'private-one')->firstOrFail();

        $this->flushSession();
        $this->actingAs(User::factory()->create())->get(route('sites.edit', $site))->assertNotFound();
    }

    public function test_the_dashboard_uses_no_native_dialogs(): void
    {
        // A native confirm() freezes the page for a browser-driving agent.
        $user = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($user)->post('/sites', ['site_id' => 'dialog-free']);

        $html = $this->actingAs($user)->get('/sites')->assertOk()->getContent();

        $this->assertStringNotContainsString('confirm(', $html);
        $this->assertStringNotContainsString('alert(', $html);
        $this->assertStringContainsString('Delete permanently', $html);
    }
}
