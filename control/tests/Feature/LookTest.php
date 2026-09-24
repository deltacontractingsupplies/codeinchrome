<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * cic.lookUrl: a page as one of the SITE's users sees it, in a real tab.
 * Mostly about what it must never do: hand the site's session to the
 * browser, run the site's script on the platform's address, or be usable
 * by anyone but the owner.
 */
class LookTest extends TestCase
{
    private User $owner;

    private Site $site;

    /** @var list<array{path: string, cookie: ?string}> */
    private array $asked = [];

    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1'],
        ]);
        $this->owner = User::factory()->create();
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'crm', 'domain' => 'crm.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20020]);
        $this->pages = [
            '/tasks' => ['status' => 200, 'headers' => ['content-type' => 'text/html; charset=UTF-8'],
                'body' => '<html><head><title>Tasks</title></head><body><h1>Open tasks</h1><script>steal()</script></body></html>'],
        ];
        Http::fake(['127.0.0.1:9441/*' => function ($r) {
            if (str_ends_with($r->url(), '/login-cookie')) {
                return Http::response(['ok' => true, 'name' => 'crm_session', 'value' => 'SECRET-SESSION-VALUE']);
            }
            $this->asked[] = ['path' => $r['path'], 'cookie' => ((array) ($r['headers'] ?? []))['cookie'] ?? null];

            return Http::response(['ok' => true, 'response' => $this->pages[$r['path']]
                ?? ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => '<html><body>missing</body></html>']]);
        }]);
    }

    private function url(array $params = []): string
    {
        return $this->actingAs($this->owner)->postJson(route('sites.look', $this->site), $params + ['path' => '/tasks', 'as' => 1])
            ->assertOk()->json('url');
    }

    public function test_the_page_is_shown_as_the_sites_user_and_never_run(): void
    {
        $response = $this->actingAs($this->owner)->get($this->url())->assertOk()
            ->assertSee('Open tasks')
            ->assertSee('<base href="https://crm.codeinchrome.com/tasks">', false)
            ->assertSee("the site's user 1", false);

        // Signed in on the SERVER: the session went to the site, never to the browser.
        $this->assertSame('crm_session=SECRET-SESSION-VALUE', $this->asked[0]['cookie']);
        $this->assertStringNotContainsString('SECRET-SESSION-VALUE', $response->getContent());
        $this->assertNull($response->headers->get('Set-Cookie') ? (str_contains($response->headers->get('Set-Cookie'), 'crm_session') ? 'leaked' : null) : null);

        // Sandboxed with nothing allowed: no script, no forms, an opaque origin.
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringStartsWith('sandbox;', $csp);
        $this->assertStringNotContainsString('allow-', $csp);
        $this->assertStringContainsString("default-src 'none'", $csp);
        $this->assertStringContainsString("form-action 'none'", $csp);
        $this->assertStringContainsString('img-src https://crm.codeinchrome.com data:', $csp);
        $this->assertStringNotContainsString('script-src', $csp);
    }

    public function test_only_the_owner_gets_an_address_or_can_use_one(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->postJson(route('sites.look', $this->site), ['path' => '/tasks'])->assertNotFound();

        $url = $this->url();
        $this->actingAs($stranger)->get($url)->assertNotFound();
        $this->assertSame([], $this->asked);
    }

    public function test_a_token_works_for_its_own_site_only(): void
    {
        $other = Site::create(['user_id' => $this->owner->id, 'site_id' => 'blog', 'domain' => 'blog.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20021]);
        $token = basename($this->url());

        $this->actingAs($this->owner)->get(route('sites.look.show', [$other, $token]))->assertForbidden();
        $this->assertSame([], $this->asked);
    }

    public function test_an_address_stays_its_issuers_even_if_the_site_changes_hands(): void
    {
        $url = $this->url();
        $newOwner = User::factory()->create();
        $this->site->forceFill(['user_id' => $newOwner->id])->save();

        // The new owner owns the site, but this address was issued to someone else.
        $this->actingAs($newOwner)->get($url)->assertForbidden();
        $this->assertSame([], $this->asked);
    }

    public function test_a_changed_or_expired_address_is_refused(): void
    {
        $url = $this->url();
        $tampered = substr($url, 0, -1).(str_ends_with($url, 'a') ? 'b' : 'a');
        $this->actingAs($this->owner)->get($tampered)->assertForbidden();
        $this->actingAs($this->owner)->get(route('sites.look.show', [$this->site, (string) \Illuminate\Support\Str::uuid()]))->assertForbidden();
        $this->actingAs($this->owner)->get(route('sites.look.show', [$this->site, 'not-a-token']))->assertNotFound();

        $this->travel(11)->minutes();
        $this->actingAs($this->owner)->get($url)->assertForbidden();
        $this->assertSame([], $this->asked, 'nothing is fetched on a refused address');
    }

    public function test_redirects_inside_the_site_are_followed_and_one_leaving_it_is_not(): void
    {
        $this->pages['/old'] = ['status' => 302, 'headers' => ['location' => 'https://crm.codeinchrome.com/tasks'], 'body' => ''];
        $this->pages['/away'] = ['status' => 302, 'headers' => ['location' => 'https://evil.example/phish'], 'body' => ''];

        $this->actingAs($this->owner)->get($this->url(['path' => '/old']))->assertOk()->assertSee('Open tasks');
        $this->assertSame(['/old', '/tasks'], array_column($this->asked, 'path'));

        $this->actingAs($this->owner)->get($this->url(['path' => '/away']))->assertOk()
            ->assertSee('redirects away from the site, to https://evil.example/phish');
        $this->assertNotContains('/phish', array_column($this->asked, 'path'));
    }

    public function test_not_a_page_and_an_unreachable_host_are_said_never_a_500(): void
    {
        $this->pages['/data.json'] = ['status' => 200, 'headers' => ['content-type' => 'application/json'], 'body' => '{"a":1}'];
        $this->actingAs($this->owner)->get($this->url(['path' => '/data.json']))->assertOk()->assertSee('not a page');

        $url = $this->url();
        Http::fake(['127.0.0.1:9441/*' => fn () => throw new ConnectionException('connection refused')]);
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('could not be asked just now');
    }

    public function test_a_login_the_agent_did_itself_is_used_and_kept_encrypted(): void
    {
        // An app with no Laravel users (a shared password): the agent signed in
        // through its own form with cic.request, and passes that session on.
        $url = $this->actingAs($this->owner)->postJson(route('sites.look', $this->site),
            ['path' => '/tasks', 'cookie' => 'barber_session=AGENT-SESSION'])->assertOk()->json('url');

        $stored = \Illuminate\Support\Facades\Cache::get('look:'.basename($url));
        $this->assertStringNotContainsString('AGENT-SESSION', json_encode($stored), 'kept encrypted');

        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Open tasks')
            ->assertDontSee('AGENT-SESSION');
        $this->assertSame('barber_session=AGENT-SESSION', $this->asked[0]['cookie']);
    }

    public function test_a_cookie_cannot_carry_another_header_and_as_and_cookie_do_not_mix(): void
    {
        $this->actingAs($this->owner)->postJson(route('sites.look', $this->site), ['path' => '/tasks', 'cookie' => "a=b\r\nX-Evil: 1"])
            ->assertUnprocessable();
        $this->actingAs($this->owner)->postJson(route('sites.look', $this->site), ['path' => '/tasks', 'as' => 1, 'cookie' => 'a=b'])
            ->assertUnprocessable();
    }
}
