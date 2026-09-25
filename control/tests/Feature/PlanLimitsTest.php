<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    private bool $hostDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'billing.plans.starter.variant_id' => '777',
        ]);

        Http::fake([
            '127.0.0.1:944*/v1/sites/*/limits' => function () {
                if ($this->hostDown) {
                    throw new \Illuminate\Http\Client\ConnectionException('tunnel down');
                }

                return Http::response(['ok' => true, 'applied' => ['cpuMemory' => 'applied', 'disk' => 'grown']]);
            },
            '127.0.0.1:944*/v1/usage' => Http::response(['ok' => true, 'usage' => [
                ['id' => 'shop', 'diskUsedBytes' => 80 << 20, 'diskSizeBytes' => 1 << 30, 'diskMounted' => true, 'databaseBytes' => 3 << 20],
            ]]),
        ]);
    }

    private function siteFor(User $user, string $id = 'shop'): Site
    {
        return Site::create([
            'user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m', 'disk_gb' => 5, 'port' => 20000,
        ]);
    }

    private function upgradeWebhook(User $user)
    {
        $body = json_encode([
            'meta' => ['custom_data' => ['user_id' => (string) $user->id]],
            'data' => ['id' => 'sub_up', 'attributes' => [
                'variant_id' => '777', 'status' => 'active', 'user_email' => $user->email,
                'renews_at' => now()->addMonth()->toIso8601String(), 'ends_at' => null,
            ]],
        ]);

        return $this->call('POST', '/webhooks/lemonsqueezy', [], [], [], [
            'HTTP_X_Signature' => hash_hmac('sha256', $body, self::SECRET),
            'HTTP_X_Event_Name' => 'subscription_created',
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_an_upgrade_is_applied_to_sites_that_already_exist(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $site = $this->siteFor($user);

        $this->upgradeWebhook($user)->assertOk();

        $pro = config('billing.plans.starter');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/v1/sites/shop/limits')
            && $r['cpuLimit'] === $pro['cpu'] && $r['memLimit'] === $pro['memory'] && $r['diskGb'] === $pro['disk_gb']);

        $site->refresh();
        $this->assertSame($pro['cpu'], $site->cpu_limit);
        $this->assertSame($pro['disk_gb'], $site->disk_gb);
        $this->assertFalse($site->limits_pending);
    }

    public function test_a_host_being_down_never_fails_the_payment_and_is_retried(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $site = $this->siteFor($user);
        $this->hostDown = true;

        // The payment is recorded regardless: a 500 here would make Lemon
        // Squeezy retry an event we have already applied.
        $this->upgradeWebhook($user)->assertOk();
        $this->assertSame('starter', $user->fresh()->plan);
        $this->assertTrue($site->fresh()->limits_pending, 'An unapplied upgrade must be marked, not forgotten.');

        // The host comes back; the retry finishes the job.
        $this->hostDown = false;
        $this->assertSame(0, Artisan::call('fleet:apply-limits'));
        $this->assertFalse($site->fresh()->limits_pending);
        $this->assertSame(config('billing.plans.starter.cpu'), $site->fresh()->cpu_limit);
    }

    public function test_a_downgrade_never_records_a_smaller_disk_than_the_site_has(): void
    {
        $user = User::factory()->create(['plan' => 'free']); // 2 GB plan
        $site = $this->siteFor($user);                        // but the site has 5 GB

        app(\App\Fleet\PlanLimits::class)->applyTo($user);

        // The host never shrinks a disk, so the row must keep saying 5.
        $this->assertSame(5, $site->fresh()->disk_gb);
    }

    public function test_usage_is_recorded_with_the_time_it_was_measured(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        $site = $this->siteFor($user);

        $this->assertSame(0, Artisan::call('fleet:sync-usage'));

        $site->refresh();
        $this->assertSame(80 << 20, (int) $site->disk_used_bytes);
        $this->assertSame(3 << 20, (int) $site->database_bytes);
        $this->assertSame((80 << 20) + (3 << 20), $site->totalBytes());
        $this->assertNotNull($site->usage_at);
    }

    public function test_provisioning_asks_for_the_plans_disk_size(): void
    {
        config(['fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'], 'fleet.zone' => 'codeinchrome.com']);
        Http::fake([
            'api.cloudflare.com/*' => fn ($r) => Http::response(['success' => true, 'errors' => [], 'result' => $r->method() === 'GET' ? [] : ['id' => 'rec']]),
            '127.0.0.1:944*/v1/host' => Http::response(['ok' => true, 'version' => config('fleet.min_agent_version'), 'sites' => 0, 'running' => 0]),
            '127.0.0.1:944*/v1/sites' => Http::response(['ok' => true, 'site' => ['id' => 'big', 'port' => 20000]], 201),
        ]);
        $user = User::factory()->create(['plan' => 'starter']);

        $site = \App\Fleet\Provisioner::make()->provision($user, 'big');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/sites') && $r['diskGb'] === config('billing.plans.starter.disk_gb'));
        $this->assertSame(config('billing.plans.starter.disk_gb'), $site->disk_gb);
    }

    public function test_a_site_whose_limits_drifted_from_its_plan_is_brought_back_in_line(): void
    {
        // Made under older plan settings: double the free plan (the audit found three).
        $user = User::factory()->create(['plan' => 'free']);
        $site = $this->siteFor($user);
        $site->update(['cpu_limit' => '1.0', 'memory_limit' => '640m', 'limits_pending' => false]);

        $this->assertSame(0, Artisan::call('fleet:apply-limits'));
        $this->assertSame(config('billing.plans.free.cpu'), $site->fresh()->cpu_limit);
        $this->assertSame(config('billing.plans.free.memory'), $site->fresh()->memory_limit);

        // In line now: nothing more is asked of the host.
        $this->assertSame(0, Artisan::call('fleet:apply-limits'));
        $this->assertStringNotContainsString($site->site_id, Artisan::output());
    }
}
