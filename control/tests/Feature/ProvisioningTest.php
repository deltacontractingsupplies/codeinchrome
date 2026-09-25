<?php

namespace Tests\Feature;

use App\Fleet\AgentRefused;
use App\Fleet\Dns;
use App\Fleet\Provisioner;
use App\Fleet\Stock;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => [
                'h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 2],
                'h2' => ['ip' => '10.0.0.2', 'tunnel_port' => 9442, 'capacity' => 2],
            ],
            'fleet.zone' => 'codeinchrome.com',
            'fleet.tokens' => ['h1' => 'token-h1', 'h2' => 'token-h2'],
            'fleet.cloudflare' => [
                'token' => 'test-cf-token',
                'zone_id' => 'test-zone-id',
                'zone_name' => 'codeinchrome.com',
            ],
        ]);
    }

    /**
     * Cloudflare's LIST endpoint returns a JSON array and its create endpoint
     * returns a single object, so the fake has to distinguish them by method.
     * A fake that returned the same shape for both hid a real crash in the
     * list parser behind a generic "provisioning failed".
     */
    /** @var array<string, string> fqdn => record id, as Cloudflare would hold it */
    private array $dnsRecords = [];

    private array $deleteResponse = [
        'ok' => true,
        'parts' => ['container' => 'removed', 'vhost' => 'removed', 'data' => 'removed', 'log' => 'removed', 'network' => 'removed'],
    ];

    private int $deleteStatus = 200;

    /**
     * Defaults to the CONFIGURED minimum rather than a literal.
     *
     * It used to be hardcoded, and when fleet.min_agent_version was raised the
     * fake kept answering the old number - so every provisioning test failed
     * for a reason that had nothing to do with provisioning. A test fixture
     * that has to be kept in step with config by hand will eventually not be.
     */
    private string $agentVersion = '';

    private ?array $createResponse = null;

    private int $createStatus = 201;

    /**
     * Configure the fake by SETTING these, never by calling Http::fake() a
     * second time - stubs merge and the first match wins, so a later fake is
     * silently ignored and the test asserts against the earlier response.
     */
    private function failCreate(string $hint): void
    {
        $this->createResponse = ['ok' => false, 'error' => 'create_failed', 'hint' => $hint];
        $this->createStatus = 422;
    }

    private function succeedCreate(): void
    {
        $this->createResponse = null;
        $this->createStatus = 201;
    }

    private function fakeAgent(array $overrides = []): void
    {
        $this->dnsRecords = [];
        $this->createResponse = null;
        $this->createStatus = 201;
        $this->agentVersion = config('fleet.min_agent_version');

        // Overrides go FIRST (the + operator keeps the left side's order and
        // values): the first matching stub wins, so an override appended after
        // the catch-all "/v1/sites*" would never be reached.
        Http::fake($overrides + [
            // STATEFUL on purpose. A stateless fake whose GET always returned
            // an empty list made delete() correctly find nothing and no-op,
            // so a test asserting "the DNS record is withdrawn on failure"
            // passed against a fake that could never have a record to
            // withdraw. It was testing the fake, not the rollback.
            'api.cloudflare.com/*' => function ($request) {
                $url = $request->url();
                $body = ['success' => true, 'errors' => [], 'result' => []];

                if ($request->method() === 'GET') {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $name = $query['name'] ?? '';
                    $body['result'] = isset($this->dnsRecords[$name])
                        ? [['id' => $this->dnsRecords[$name], 'name' => $name]]
                        : [];
                } elseif ($request->method() === 'POST') {
                    $id = 'rec-'.count($this->dnsRecords);
                    $this->dnsRecords[$request['name']] = $id;
                    $body['result'] = ['id' => $id];
                } elseif ($request->method() === 'DELETE') {
                    $id = basename((string) parse_url($url, PHP_URL_PATH));
                    $this->dnsRecords = array_filter($this->dnsRecords, fn ($v) => $v !== $id);
                    $body['result'] = ['id' => $id];
                }

                return Http::response($body);
            },
            // Method-aware for the same reason the Cloudflare fake is:
            // Http::fake() MERGES stubs and the first match wins, so a second
            // Http::fake() later in a test does NOT override an earlier one.
            // A test that re-faked the DELETE this way silently received the
            // original create response instead, asserted on it, and passed
            // without ever exercising the delete path.
            // A CLOSURE, not Http::response(...). Http::response() is built
            // when the fake is registered, so it would capture whatever
            // $agentVersion was at setup time and ignore a test that changes
            // it afterwards - the stub would answer 0.2.0 forever.
            '127.0.0.1:944*/v1/host' => fn () => Http::response(
                ['ok' => true, 'version' => $this->agentVersion, 'sites' => 0, 'running' => 0]
            ),
            '127.0.0.1:944*/v1/sites*' => function ($request) {
                if ($request->method() === 'DELETE') {
                    return Http::response($this->deleteResponse, $this->deleteStatus);
                }

                return Http::response(
                    $this->createResponse ?? ['ok' => true, 'site' => ['id' => 'shop', 'port' => 20000, 'state' => 'running']],
                    $this->createStatus,
                );
            },
        ]);
    }

    public function test_it_provisions_a_site_and_records_what_the_agent_reported(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);

        $site = Provisioner::make()->provision($user, 'shop');

        $this->assertSame('live', $site->status);
        $this->assertSame('shop.codeinchrome.com', $site->domain);
        $this->assertSame(20000, $site->port, 'The port must come from the agent, not be assumed.');
        $this->assertNotNull($site->provisioned_at);
    }

    public function test_a_new_free_site_is_kept_out_of_search_engines_for_a_week_and_a_paid_one_is_not(): void
    {
        $this->fakeAgent();
        // Frozen, not travelled: a jump in time would make the fleet's capacity
        // figures look stale, and a free site is only sold into known room.
        $now = $this->freezeSecond();

        $free = Provisioner::make()->provision(User::factory()->create(['plan' => 'free']), 'fresh-cafe');
        $this->assertEquals($now->copy()->addDays(7), $free->fresh()->noindex_until);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/v1/sites/fresh-cafe/indexing') && $r['noIndex'] === true);

        $paid = Provisioner::make()->provision(User::factory()->create(['plan' => 'starter']), 'paid-cafe');
        $this->assertNull($paid->fresh()->noindex_until);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/v1/sites/paid-cafe/indexing'));
    }

    public function test_a_host_that_misses_the_noindex_call_does_not_fail_the_site(): void
    {
        $this->fakeAgent(['127.0.0.1:944*/v1/sites/*/indexing' => Http::response(['ok' => false], 500)]);

        $site = Provisioner::make()->provision(User::factory()->create(['plan' => 'free']), 'fresh-cafe');

        $this->assertSame('live', $site->status);
        $this->assertNotNull($site->fresh()->noindex_until, 'sites:indexing retries it on the next hour');
    }

    public function test_it_enforces_the_plan_site_limit(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'free']); // 1 site

        Provisioner::make()->provision($user, 'first');

        $this->expectExceptionMessage('The Free trial plan includes 1 site and you have 1');
        Provisioner::make()->provision($user, 'second');
    }

    public function test_a_downgraded_user_keeps_existing_sites_but_cannot_add_more(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']); // 3 sites
        foreach (['a-site', 'b-site', 'c-site'] as $id) {
            Provisioner::make()->provision($user, $id);
        }

        // A webhook downgrade must never delete a customer's work.
        $user->update(['plan' => 'free']);

        $this->assertSame(3, $user->sites()->where('status', 'live')->count());
        $this->expectExceptionMessage('The Free trial plan includes 1 site and you have 3');
        Provisioner::make()->provision($user, 'd-site');
    }

    public function test_site_names_are_unique_across_the_whole_fleet(): void
    {
        $this->fakeAgent();
        Provisioner::make()->provision(User::factory()->create(['plan' => 'starter']), 'shop');

        $this->expectExceptionMessage('The name "shop" is taken');
        Provisioner::make()->provision(User::factory()->create(['plan' => 'starter']), 'shop');
    }

    public function test_it_refuses_reserved_and_malformed_names(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);

        foreach (['www', 'admin', 'api', 'h1', 'app', 'panel'] as $reserved) {
            $message = null;
            try {
                Provisioner::make()->provision($user, $reserved);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertNotNull($message, "Provisioned the reserved name \"$reserved\".");
            $this->assertStringContainsString('reserved', $message);
        }

        foreach (['../etc', 'Upper', 'a', 'ends-', '-starts', 'has--double', 'has_underscore', 'has.dot'] as $bad) {
            $message = null;
            try {
                Provisioner::make()->provision($user, $bad);
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
            }
            $this->assertNotNull($message, "Provisioned the malformed name \"$bad\".");
        }

        $this->assertSame(0, Site::count(), 'A rejected name must leave no row behind.');
    }

    public function test_a_failed_agent_call_leaves_nothing_serving(): void
    {
        $this->fakeAgent();
        $this->failCreate('no disk space');
        $user = User::factory()->create(['plan' => 'starter']);

        try {
            Provisioner::make()->provision($user, 'doomed');
            $this->fail('Provisioning should have failed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('no disk space', $e->getMessage(), 'The real reason must reach the caller.');
        }

        $site = Site::where('site_id', 'doomed')->first();
        $this->assertSame('failed', $site->status, 'A failed site is neither live nor silently deleted.');
        $this->assertStringContainsString('no disk space', $site->last_error);

        // The DNS record must be withdrawn, or the name resolves to a host
        // that will never serve it. Asserted against the fake's state rather
        // than against "a DELETE was sent": what matters is that no record
        // survives, not that a particular call was made.
        $this->assertSame([], $this->dnsRecords, 'A failed provision must leave no DNS record behind.');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'cloudflare'));
    }

    public function test_a_cleanly_failed_name_can_be_retried(): void
    {
        $this->fakeAgent();
        $this->failCreate('transient');
        $user = User::factory()->create(['plan' => 'starter']);

        try {
            Provisioner::make()->provision($user, 'retry-me');
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame('failed', Site::where('site_id', 'retry-me')->first()->status);

        // Nothing exists anywhere, so the customer must be able to try again
        // with the name they chose.
        $this->succeedCreate();
        $site = Provisioner::make()->provision($user, 'retry-me');

        $this->assertSame('live', $site->status);
        $this->assertSame(1, Site::where('site_id', 'retry-me')->count(), 'The stale failed row must not linger.');
    }

    public function test_it_refuses_to_provision_onto_an_agent_too_old_to_build_the_site(): void
    {
        $this->fakeAgent();
        // One patch below whatever the minimum currently is, so this test
        // keeps meaning the same thing when the minimum moves.
        $this->agentVersion = '0.0.1';
        $user = User::factory()->create(['plan' => 'starter']);

        // Not a try/catch with fail() inside it: PHPUnit's fail() throws an
        // AssertionFailedError, which extends RuntimeException, so a
        // `catch (RuntimeException)` swallows the very failure it is meant to
        // report and the test passes while proving nothing.
        $message = null;
        try {
            Provisioner::make()->provision($user, 'stale-host');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertNotNull($message, 'Provisioned onto an agent that cannot seed an app or generate a key.');
        $this->assertStringContainsString('0.0.1', $message);
        $this->assertStringContainsString('deploy-host.sh', $message, 'The error must say how to fix it.');

        $this->assertSame([], $this->dnsRecords, 'No DNS record for a site that was never built.');
    }

    public function test_an_unreachable_agent_is_recorded_as_unknown_not_as_clean_failure(): void
    {
        $this->fakeAgent([
            '127.0.0.1:944*/v1/sites*' => fn () => throw new ConnectionException('tunnel down'),
        ]);
        $user = User::factory()->create(['plan' => 'starter']);

        try {
            Provisioner::make()->provision($user, 'unknown-state');
        } catch (\RuntimeException) {
            // expected
        }

        $site = Site::where('site_id', 'unknown-state')->first();
        $this->assertNotNull($site, 'The row must survive: it is the only record a container may exist.');
        $this->assertSame('orphaned', $site->status, 'Unknown host state is not the same as a clean failure.');
        $this->assertStringContainsString('UNKNOWN', $site->last_error);

        // And the name must stay held, or another customer could be given a
        // name a still-running container of this one is serving.
        $this->expectExceptionMessage('is taken');
        Provisioner::make()->provision(User::factory()->create(['plan' => 'starter']), 'unknown-state');
    }

    public function test_it_packs_hosts_and_refuses_to_overflow_a_full_fleet(): void
    {
        $this->fakeAgent();
        // Two Starter accounts, 3 sites each: more than the fleet can place.
        [$a, $b] = User::factory()->count(2)->create(['plan' => 'starter'])->all();

        // capacity is 2 per host, 2 hosts = 4 sites total
        foreach (['one' => $a, 'two' => $a, 'three' => $a, 'four' => $b] as $id => $user) {
            Provisioner::make()->provision($user, $id);
        }

        $this->assertSame(2, Site::where('host', 'h1')->count());
        $this->assertSame(2, Site::where('host', 'h2')->count());

        $this->expectExceptionMessage('The fleet is at capacity');
        Provisioner::make()->provision($b, 'five');
    }

    public function test_a_fully_removed_site_is_deleted_from_our_records(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);
        $site = Provisioner::make()->provision($user, 'gone');

        $parts = Provisioner::make()->destroy($site->fresh());

        $this->assertNotContains('failed', $parts);
        $this->assertNull(Site::find($site->id));
        $this->assertSame([], $this->dnsRecords, 'The DNS record must go with the site.');
    }

    public function test_a_delete_that_found_nothing_does_not_claim_a_removal(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);
        $site = Provisioner::make()->provision($user, 'not-here');

        // What the agent reports when the site was never on this host.
        // `docker rm -f` exits 0 for a container that does not exist, so
        // without the absent/removed distinction this reads as success.
        $this->deleteResponse = [
            'ok' => true,
            'parts' => ['container' => 'absent', 'vhost' => 'absent', 'data' => 'absent', 'log' => 'absent', 'network' => 'absent'],
        ];

        $parts = Provisioner::make()->destroy($site->fresh());

        $this->assertSame('absent', $parts['container']);
        $this->assertNotContains('removed', array_diff_key($parts, ['dns' => null]),
            'Nothing was on the host, so nothing may be reported as removed.');
    }

    public function test_destroy_reports_per_part_and_keeps_the_row_when_something_survives(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);
        $site = Provisioner::make()->provision($user, 'going');

        $this->deleteResponse = [
            'ok' => false, 'error' => 'partially_removed',
            'parts' => ['container' => 'removed', 'vhost' => 'removed', 'data' => 'failed', 'log' => 'removed', 'network' => 'removed'],
        ];
        $this->deleteStatus = 500;

        try {
            Provisioner::make()->destroy($site->fresh());
            $this->assertTrue(false, 'The agent reported a partial removal; destroy must not report success.');
        } catch (AgentRefused $e) {
            $this->assertSame(
                ['container' => 'removed', 'vhost' => 'removed', 'data' => 'failed', 'log' => 'removed', 'network' => 'removed'],
                $e->detail['parts'],
                'The per-part breakdown must survive to the caller, not be flattened to a boolean.'
            );
        }

        $this->assertNotNull(
            Site::find($site->id),
            'A site whose data survived on the host must not be deleted from our records.'
        );
        $this->assertSame('deleting', Site::find($site->id)->status);
    }

    public function test_a_site_record_is_proxied_through_cloudflare(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::sequence()
            ->push(['success' => true, 'errors' => [], 'result' => []])
            ->push(['success' => true, 'errors' => [], 'result' => ['id' => 'rec1']])]);

        (new Dns('t', 'z', 'codeinchrome.com'))->upsert('shop', '10.0.0.1');

        // Proxied, so visitors reach Cloudflare (Universal SSL, no per-site
        // ACME certificate) and never the host's own address.
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r['name'] === 'shop.codeinchrome.com' && $r['proxied'] === true && $r['ttl'] === 1);
    }

    public function test_a_new_site_goes_to_the_host_with_the_most_memory_to_spare_and_never_to_a_silent_one(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);

        // h1 reports more memory, but its last report is stale: it takes nothing.
        Stock::remember('h1', ['cpus' => 64, 'memTotalBytes' => 512 * 1024 ** 3, 'diskTotalBytes' => 4096 * 1024 ** 3]);
        $this->travel(11)->minutes();
        Stock::remember('h2', ['cpus' => 4, 'memTotalBytes' => 8 * 1024 ** 3, 'diskTotalBytes' => 100 * 1024 ** 3]);

        $this->assertSame('h2', Provisioner::make()->provision($user, 'first')->host);

        // Both fresh now; h1 has far more to spare.
        Stock::remember('h1', ['cpus' => 64, 'memTotalBytes' => 512 * 1024 ** 3, 'diskTotalBytes' => 4096 * 1024 ** 3]);
        $this->assertSame('h1', Provisioner::make()->provision($user, 'second')->host);
    }

    public function test_a_draining_host_takes_no_new_sites(): void
    {
        $this->fakeAgent();
        config(['fleet.hosts.h1.state' => 'draining']);
        $user = User::factory()->create(['plan' => 'starter']);

        foreach (['one', 'two'] as $id) {
            $this->assertSame('h2', Provisioner::make()->provision($user, $id)->host);
        }
        $this->expectExceptionMessage('The fleet is at capacity');
        Provisioner::make()->provision($user, 'three'); // h2 is full (capacity 2), h1 is draining
    }
}
