<?php

namespace Tests\Feature;

use App\Fleet\AgentUnreachable;
use App\Fleet\Dns;
use App\Fleet\Provisioner;
use App\Models\Site;
use App\Models\User;
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
        'removed' => ['container' => true, 'vhost' => true, 'data' => true, 'log' => true, 'network' => true],
    ];

    private int $deleteStatus = 200;

    private function fakeAgent(array $overrides = []): void
    {
        $this->dnsRecords = [];

        Http::fake(array_merge([
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
                    $id = 'rec-' . count($this->dnsRecords);
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
            '127.0.0.1:944*/v1/sites*' => function ($request) {
                if ($request->method() === 'DELETE') {
                    return Http::response($this->deleteResponse, $this->deleteStatus);
                }

                return Http::response(['ok' => true, 'site' => [
                    'id' => 'shop', 'port' => 20000, 'state' => 'running',
                ]], 201);
            },
        ], $overrides));
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

    public function test_it_enforces_the_plan_site_limit(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'free']); // 1 site

        Provisioner::make()->provision($user, 'first');

        $this->expectExceptionMessage('The Free plan includes 1 site and you have 1');
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
        $this->expectExceptionMessage('The Free plan includes 1 site and you have 3');
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
            try {
                Provisioner::make()->provision($user, $reserved);
                $this->fail("Provisioned the reserved name \"$reserved\".");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('reserved', $e->getMessage());
            }
        }

        foreach (['../etc', 'Upper', 'a', 'ends-', '-starts', 'has--double', 'has_underscore', 'has.dot'] as $bad) {
            try {
                Provisioner::make()->provision($user, $bad);
                $this->fail("Provisioned the malformed name \"$bad\".");
            } catch (\RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }

        $this->assertSame(0, Site::count(), 'A rejected name must leave no row behind.');
    }

    public function test_a_failed_agent_call_leaves_nothing_serving(): void
    {
        $this->fakeAgent([
            '127.0.0.1:944*/v1/sites*' => Http::response(
                ['ok' => false, 'error' => 'create_failed', 'hint' => 'no disk space'], 422
            ),
        ]);
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

    public function test_an_unreachable_agent_is_recorded_as_unknown_not_as_clean_failure(): void
    {
        $this->fakeAgent([
            '127.0.0.1:944*/v1/sites*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('tunnel down'),
        ]);
        $user = User::factory()->create(['plan' => 'starter']);

        try {
            Provisioner::make()->provision($user, 'unknown-state');
        } catch (\RuntimeException) {
            // expected
        }

        $site = Site::where('site_id', 'unknown-state')->first();
        $this->assertNotNull($site, 'The row must survive: it is the only record a container may exist.');
        $this->assertSame('failed', $site->status);
        $this->assertStringContainsString('UNKNOWN', $site->last_error);
    }

    public function test_it_packs_hosts_and_refuses_to_overflow_a_full_fleet(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'studio']); // 40 sites

        // capacity is 2 per host, 2 hosts = 4 sites total
        foreach (['one', 'two', 'three', 'four'] as $id) {
            Provisioner::make()->provision($user, $id);
        }

        $this->assertSame(2, Site::where('host', 'h1')->count());
        $this->assertSame(2, Site::where('host', 'h2')->count());

        $this->expectExceptionMessage('The fleet is at capacity');
        Provisioner::make()->provision($user, 'five');
    }

    public function test_a_fully_removed_site_is_deleted_from_our_records(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);
        $site = Provisioner::make()->provision($user, 'gone');

        $parts = Provisioner::make()->destroy($site->fresh());

        $this->assertNotContains(false, $parts);
        $this->assertNull(Site::find($site->id));
        $this->assertSame([], $this->dnsRecords, 'The DNS record must go with the site.');
    }

    public function test_destroy_reports_per_part_and_keeps_the_row_when_something_survives(): void
    {
        $this->fakeAgent();
        $user = User::factory()->create(['plan' => 'starter']);
        $site = Provisioner::make()->provision($user, 'going');

        $this->deleteResponse = [
            'ok' => false, 'error' => 'partially_removed',
            'removed' => ['container' => true, 'vhost' => true, 'data' => false, 'log' => true, 'network' => true],
        ];
        $this->deleteStatus = 500;

        try {
            Provisioner::make()->destroy($site->fresh());
            $this->fail('The agent reported a partial removal; destroy must not report success.');
        } catch (\App\Fleet\AgentRefused $e) {
            $this->assertSame(
                ['container' => true, 'vhost' => true, 'data' => false, 'log' => true, 'network' => true],
                $e->detail['removed'],
                'The per-part breakdown must survive to the caller, not be flattened to a boolean.'
            );
        }

        $this->assertNotNull(
            Site::find($site->id),
            'A site whose data survived on the host must not be deleted from our records.'
        );
        $this->assertSame('deleting', Site::find($site->id)->status);
    }
}
