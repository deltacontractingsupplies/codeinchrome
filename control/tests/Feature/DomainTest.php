<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainTest extends TestCase
{
    /** What public DNS says, keyed "name|TYPE". Set per test; never re-fake. */
    private array $dns = [];

    private bool $agentFails = false;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'fleet.zone' => 'codeinchrome.com',
        ]);

        Http::fake([
            'cloudflare-dns.com/*' => fn ($r) => $this->dnsAnswer($r),
            'dns.google/*' => fn ($r) => $this->dnsAnswer($r),
            '127.0.0.1:944*/v1/sites/*/aliases' => fn () => $this->agentFails
                ? Http::response(['ok' => false, 'error' => 'aliases_failed', 'hint' => 'caddy reload failed'], 422)
                : Http::response(['ok' => true, 'site' => []]),
        ]);

        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m',
        ]);
    }

    private function dnsAnswer($r)
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $type = strtoupper($q['type']);
        $answers = array_map(
            fn ($v) => ['type' => $type === 'TXT' ? 16 : 1, 'data' => $type === 'TXT' ? "\"$v\"" : $v],
            $this->dns[$q['name'] . '|' . $type] ?? [],
        );

        return Http::response(['Status' => 0, 'Answer' => $answers]);
    }

    private function add(string $domain)
    {
        return $this->actingAs($this->owner)->post(route('domains.store', $this->site), ['domain' => $domain]);
    }

    public function test_a_domain_is_attached_only_after_both_records_are_published(): void
    {
        $this->add('www.example.com')->assertRedirect();
        $d = SiteDomain::where('domain', 'www.example.com')->firstOrFail();

        // Nothing published yet.
        $this->actingAs($this->owner)->post(route('domains.verify', [$this->site, $d]))->assertSessionHas('error');
        $this->assertNull($d->fresh()->verified_at);

        // TXT but pointing elsewhere: still not attached.
        $this->dns['_codeinchrome-challenge.www.example.com|TXT'] = [$d->token];
        $this->dns['www.example.com|A'] = ['203.0.113.9'];
        $this->actingAs($this->owner)->post(route('domains.verify', [$this->site, $d]))->assertSessionHas('error');
        $this->assertStringContainsString('does not point at 10.0.0.1', $d->fresh()->last_check);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/aliases'));

        // Both right: attached, and the host is sent the full list.
        $this->dns['www.example.com|A'] = ['10.0.0.1'];
        $this->actingAs($this->owner)->post(route('domains.verify', [$this->site, $d]))->assertSessionHas('status');
        $this->assertNotNull($d->fresh()->verified_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/sites/shop/aliases') && $r['aliases'] === ['www.example.com']);
    }

    public function test_a_wrong_token_does_not_verify(): void
    {
        $this->add('app.example.com');
        $d = SiteDomain::where('domain', 'app.example.com')->firstOrFail();
        $this->dns['_codeinchrome-challenge.app.example.com|TXT'] = ['someone-elses-token'];
        $this->dns['app.example.com|A'] = ['10.0.0.1'];

        $this->actingAs($this->owner)->post(route('domains.verify', [$this->site, $d]))->assertSessionHas('error');
        $this->assertNull($d->fresh()->verified_at);
    }

    public function test_if_the_host_refuses_the_domain_is_not_recorded_as_attached(): void
    {
        $this->add('x.example.com');
        $d = SiteDomain::where('domain', 'x.example.com')->firstOrFail();
        $this->dns['_codeinchrome-challenge.x.example.com|TXT'] = [$d->token];
        $this->dns['x.example.com|A'] = ['10.0.0.1'];
        $this->agentFails = true;

        $this->actingAs($this->owner)->post(route('domains.verify', [$this->site, $d]))->assertSessionHas('error');
        $this->assertNull($d->fresh()->verified_at, 'Recorded as attached while the host is not serving it.');
    }

    public function test_platform_names_and_garbage_are_refused(): void
    {
        foreach (['codeinchrome.com', 'othersite.codeinchrome.com', 'not a domain', 'localhost', '10.0.0.1', 'x..com', '-a.com'] as $bad) {
            $this->add($bad)->assertSessionHasErrors('domain');
        }
        $this->assertSame(0, SiteDomain::count());
    }

    public function test_input_is_normalised(): void
    {
        $this->add('  https://Shop.Example.COM./path  ');
        $this->assertDatabaseHas('site_domains', ['domain' => 'shop.example.com']);

        $this->add('bücher.example.com');
        $this->assertDatabaseHas('site_domains', ['domain' => 'xn--bcher-kva.example.com']);
    }

    public function test_a_verified_domain_cannot_be_claimed_by_anyone_else_but_a_stale_claim_can(): void
    {
        $this->add('taken.example.com');
        SiteDomain::where('domain', 'taken.example.com')->update(['verified_at' => now()]);

        $other = User::factory()->create(['plan' => 'starter']);
        $theirs = Site::create(['user_id' => $other->id, 'site_id' => 'theirs', 'domain' => 'theirs.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);

        $this->flushSession();
        $this->actingAs($other)->post(route('domains.store', $theirs), ['domain' => 'taken.example.com'])->assertSessionHasErrors('domain');

        // An unverified claim older than a week can be taken over by the real owner.
        $this->flushSession();
        $this->add('squat.example.com');
        SiteDomain::where('domain', 'squat.example.com')->update(['created_at' => now()->subDays(8)]);
        $this->flushSession();
        $this->actingAs($other)->post(route('domains.store', $theirs), ['domain' => 'squat.example.com'])->assertSessionDoesntHaveErrors();
        $this->assertSame($theirs->id, SiteDomain::where('domain', 'squat.example.com')->first()->site_id);
    }

    public function test_the_free_plan_cannot_add_domains_and_strangers_get_404(): void
    {
        $this->owner->update(['plan' => 'free']);
        $this->add('free.example.com')->assertForbidden();

        $this->flushSession();
        $this->actingAs(User::factory()->create(['plan' => 'pro']))->get(route('domains.index', $this->site))->assertNotFound();
    }

    public function test_removing_a_verified_domain_resyncs_the_host(): void
    {
        $this->add('gone.example.com');
        $d = SiteDomain::where('domain', 'gone.example.com')->firstOrFail();
        $d->update(['verified_at' => now()]);

        $this->actingAs($this->owner)->delete(route('domains.destroy', [$this->site, $d]))->assertSessionHas('status');

        $this->assertNull(SiteDomain::find($d->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/aliases') && $r['aliases'] === []);
    }

    public function test_the_token_never_appears_in_serialised_output(): void
    {
        $this->add('secret.example.com');
        $this->assertArrayNotHasKey('token', SiteDomain::first()->toArray());
    }
}
