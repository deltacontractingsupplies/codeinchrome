<?php

namespace Tests\Feature;

use App\Fleet\Provisioner;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PlanNotice;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The free plan is a three-day trial: warned a day before, paused when it
 * ends, deleted after the grace period, and brought back whole by an upgrade
 * at any point before that. Driven through trials:expire against a fake agent
 * that records every call, so each test says exactly what reached a host.
 */
class TrialTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    /** @var list<string> "METHOD path body" for every agent call */
    private array $calls = [];

    private bool $hostDown = false;

    /** What the fake agent reports for the last backup: null, or an operation. */
    private ?array $backupOp = null;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'fleet.zone' => 'codeinchrome.com',
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'],
            'billing.plans.starter.variant_id' => '777',
        ]);
        Http::fake([
            'api.cloudflare.com/*' => fn ($r) => Http::response(['success' => true, 'errors' => [], 'result' => $r->method() === 'GET' ? [] : ['id' => 'rec']]),
            '127.0.0.1:9441/*' => function ($r) {
                if ($this->hostDown) {
                    throw new \Illuminate\Http\Client\ConnectionException('tunnel down');
                }
                $path = parse_url($r->url(), PHP_URL_PATH);
                $this->calls[] = trim($r->method().' '.$path.' '.$r->body());

                if (str_ends_with($path, '/backups')) {
                    if ($r->method() === 'POST') {
                        $this->backupOp = ['kind' => 'backup', 'state' => 'running', 'started' => now()->toIso8601String()];

                        return Http::response(['ok' => true, 'operation' => $this->backupOp]);
                    }

                    return Http::response(['ok' => true, 'backups' => [], 'operation' => $this->backupOp]);
                }

                return match (true) {
                    str_ends_with($path, '/suspended') => Http::response(['ok' => true, 'applied' => ['container' => 'ok']]),
                    str_ends_with($path, '/limits') => Http::response(['ok' => true, 'applied' => []]),
                    $r->method() === 'DELETE' => Http::response(['ok' => true, 'parts' => ['container' => 'removed', 'data' => 'removed']]),
                    default => Http::response(['ok' => true]),
                };
            },
        ]);
    }

    private function trialUser(string $endsIn = '+3 days', array $extra = []): User
    {
        $user = User::factory()->create(['plan' => 'free']);
        $user->forceFill(['trial_ends_at' => now()->modify($endsIn)] + $extra)->save();

        return $user;
    }

    private function siteFor(User $user, string $id = 'trial-shop', string $status = 'live'): Site
    {
        return Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
            'status' => $status, 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'disk_gb' => 2, 'port' => 20000]);
    }

    private function expire(): void
    {
        $this->assertSame(0, Artisan::call('trials:expire'));
    }

    public function test_signing_up_starts_a_three_day_trial_that_the_dashboard_counts_down(): void
    {
        $this->post('/register', ['name' => 'T', 'email' => 't@example.org', 'password' => 'a-long-enough-pass-9Q', 'password_confirmation' => 'a-long-enough-pass-9Q']); // gitleaks:allow - a throwaway test password
        $user = User::where('email', 't@example.org')->firstOrFail();

        $this->assertEqualsWithDelta(now()->addDays(3)->getTimestamp(), $user->trial_ends_at->getTimestamp(), 5);
        $this->assertTrue($user->onTrial());
        $this->actingAs($user)->get(route('dashboard'))->assertSee('data-trial="running"', false)->assertSee('Free trial:');

        // Starting it again never extends it.
        $user->forceFill(['trial_ends_at' => now()->addHour()])->save();
        $user->startTrial();
        $this->assertTrue($user->fresh()->trial_ends_at->lessThan(now()->addHours(2)));
    }

    public function test_a_day_before_the_end_the_owner_is_warned_once(): void
    {
        $soon = $this->trialUser('+20 hours');
        $later = $this->trialUser('+3 days');

        $this->expire();
        $this->expire();

        Notification::assertSentToTimes($soon, PlanNotice::class, 1);
        Notification::assertSentTo($soon, PlanNotice::class, fn ($n) => $n->kind === 'ending');
        Notification::assertNotSentTo($later, PlanNotice::class);
        $this->assertSame([], $this->calls, 'A warning touches no host.');
    }

    public function test_when_the_trial_ends_the_sites_are_paused_not_deleted(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user);

        $this->expire();

        $this->assertSame(['PUT /v1/sites/trial-shop/suspended {"suspended":true}'], $this->calls);
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertNotNull($user->fresh()->suspended_at);
        Notification::assertSentTo($user, PlanNotice::class, fn ($n) => $n->kind === 'paused'
            && $n->when->equalTo($user->fresh()->suspended_at->addDays(config('billing.trial.grace_days'))));

        // Nothing more happens until the grace period is over.
        $this->calls = [];
        $this->travel(config('billing.trial.grace_days'))->days();
        $this->travel(-1)->minutes();
        $this->expire();
        $this->assertSame([], $this->calls);
        $this->assertSame('suspended', $site->fresh()->status);
    }

    public function test_a_site_already_paused_for_inactivity_is_told_about_and_becomes_a_trial_pause(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user, status: 'suspended');
        $site->update(['paused_reason' => 'idle']);

        $this->expire();

        $this->assertSame([], $this->calls, 'already stopped: no host call');
        $this->assertSame('trial', $site->fresh()->paused_reason, 'the one-click idle wake no longer applies');
        Notification::assertSentTo($user, PlanNotice::class, fn ($n) => $n->kind === 'paused');
    }

    public function test_after_the_grace_period_the_sites_are_deleted_and_the_account_stays(): void
    {
        $user = $this->trialUser('-1 minute');
        $this->siteFor($user);
        $this->expire();

        $this->travel(config('billing.trial.grace_days'))->days();
        $this->travel(1)->minutes();
        $this->calls = [];
        $this->expire();

        $this->assertSame(['DELETE /v1/sites/trial-shop'], $this->calls);
        $this->assertSame(0, Site::count());
        $this->assertNotNull($user->fresh(), 'The account stays, so the address cannot start a second trial.');
        Notification::assertSentTo($user, PlanNotice::class, fn ($n) => $n->kind === 'deleted');

        // And it cannot build again without paying.
        $this->expectExceptionMessage('Your free trial has ended');
        Provisioner::make()->provision($user->fresh(), 'another');
    }

    public function test_a_host_that_is_down_delays_the_pause_and_never_skips_it(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user);
        $this->hostDown = true;

        $this->expire();
        $this->assertSame('live', $site->fresh()->status, 'Not marked paused when the host never heard of it.');

        $this->hostDown = false;
        $this->expire();
        $this->assertSame('suspended', $site->fresh()->status);
        Notification::assertSentToTimes($user, PlanNotice::class, 1);
    }

    public function test_operators_and_accounts_without_a_trial_clock_are_never_touched(): void
    {
        config(['fleet.admin_emails' => ['op@example.org']]);
        $op = User::factory()->create(['plan' => 'free', 'email' => 'op@example.org']);
        $op->forceFill(['trial_ends_at' => now()->subMonth()])->save();
        $this->siteFor($op, 'ops-site');
        $old = User::factory()->create(['plan' => 'free']); // from before trials: no clock
        $this->siteFor($old, 'old-site');

        $this->travel(30)->days();
        $this->expire();

        $this->assertSame([], $this->calls);
        $this->assertSame(2, Site::where('status', 'live')->count());
        Notification::assertNothingSent();
    }

    public function test_paying_brings_paused_sites_back_as_they_were(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user);
        $this->expire();
        $this->calls = [];

        $body = json_encode(['meta' => ['custom_data' => ['user_id' => (string) $user->id]],
            'data' => ['id' => 'sub_t', 'attributes' => ['variant_id' => '777', 'status' => 'active', 'user_email' => $user->email]]]);
        $this->call('POST', '/webhooks/lemonsqueezy', [], [], [], [
            'HTTP_X_Signature' => hash_hmac('sha256', $body, self::SECRET), 'HTTP_X_Event_Name' => 'subscription_created',
            'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $this->assertSame('PUT /v1/sites/trial-shop/suspended {"suspended":false}', $this->calls[0], 'Resumed before anything else.');
        $this->assertStringStartsWith('PUT /v1/sites/trial-shop/limits', $this->calls[1]);
        $this->assertSame('live', $site->fresh()->status);
        $this->assertSame(config('billing.plans.starter.disk_gb'), $site->fresh()->disk_gb);
        $this->assertNull($user->fresh()->suspended_at);
        $this->assertFalse($user->fresh()->trialExpired());

        // Nothing is deleted when the old deletion date comes round.
        $this->travel(10)->days();
        $this->calls = [];
        $this->expire();
        $this->assertSame([], $this->calls);
    }

    public function test_a_resume_that_failed_is_finished_by_the_next_run(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user);
        $this->expire();
        $user->update(['plan' => 'starter']);

        $this->hostDown = true;
        $this->expire();
        $this->assertSame('suspended', $site->fresh()->status);

        $this->hostDown = false;
        $this->expire();
        $this->assertSame('live', $site->fresh()->status);
        $this->assertNull($user->fresh()->suspended_at);
    }

    public function test_a_customer_who_lapses_to_free_is_paused_with_the_longer_grace(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        \App\Models\Subscription::create(['user_id' => $user->id, 'ls_subscription_id' => 'sub_l', 'plan' => 'starter', 'status' => 'active']);
        $user->update(['plan' => 'starter']);
        $this->siteFor($user);

        $body = json_encode(['meta' => ['custom_data' => ['user_id' => (string) $user->id]],
            'data' => ['id' => 'sub_l', 'attributes' => ['variant_id' => '777', 'status' => 'expired', 'user_email' => $user->email]]]);
        $this->call('POST', '/webhooks/lemonsqueezy', [], [], [], [
            'HTTP_X_Signature' => hash_hmac('sha256', $body, self::SECRET), 'HTTP_X_Event_Name' => 'subscription_expired',
            'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('free', $user->fresh()->plan);
        $this->assertSame([], array_filter($this->calls, fn ($c) => str_contains($c, 'suspended') || str_starts_with($c, 'DELETE')),
            'The webhook itself pauses and deletes nothing.');

        $this->travel(1)->minutes();
        $this->expire();
        $this->assertSame('suspended', Site::first()->status);
        $this->assertTrue($user->fresh()->deletesAt()->equalTo($user->fresh()->suspended_at->addDays(config('billing.trial.lapsed_grace_days'))));
    }

    public function test_a_paused_site_can_be_exported_or_deleted_but_not_worked_on(): void
    {
        $user = $this->trialUser('-1 minute');
        $site = $this->siteFor($user, 'paused-shop', 'suspended');

        $this->actingAs($user)->get(route('sites.edit', $site))->assertRedirect(route('billing'));
        $this->actingAs($user)->putJson(route('files.store', $site), ['path' => '/a.txt', 'content' => 'x'])
            ->assertStatus(423)->assertJson(['error' => 'site_paused']);
        $this->actingAs($user)->postJson(route('console.run', $site), ['tool' => 'artisan', 'args' => ['about']])->assertStatus(423);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Download database')->assertSee('data-trial', false);

        // Reading is still allowed through to the controller.
        $this->actingAs($user)->getJson(route('files.index', $site).'?path=/')->assertStatus(200);

        // Someone else learns nothing: the same 404 as any site not theirs.
        $this->actingAs(User::factory()->create())->putJson(route('files.store', $site), ['path' => '/a.txt', 'content' => 'x'])
            ->assertNotFound();
    }

    public function test_a_former_customers_site_is_never_deleted_without_a_confirmed_final_backup(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $user->forceFill(['trial_ends_at' => now()->subDays(20), 'suspended_at' => now()->subDays(10)])->save();
        \App\Models\Subscription::create(['user_id' => $user->id, 'ls_subscription_id' => 's', 'plan' => 'starter', 'status' => 'expired']);
        $site = $this->siteFor($user, 'paid-shop', 'suspended');

        // Past the grace period: the first run only starts the backup.
        $this->expire();
        $called = fn (string $call) => collect($this->calls)->contains(fn ($c) => str_starts_with($c, $call));
        $this->assertTrue($called('POST /v1/sites/paid-shop/backups'), json_encode($this->calls));
        $this->assertFalse($called('DELETE /v1/sites/paid-shop'));
        $this->assertNull($site->fresh()->final_backup);

        // A backup that failed is retried, and still nothing is deleted.
        $this->backupOp = ['kind' => 'backup', 'state' => 'failed', 'started' => now()->toIso8601String()];
        $this->expire();
        $this->assertFalse($called('DELETE /v1/sites/paid-shop'));
        $this->assertNull($site->fresh()->final_backup_started_at, 'A failed final backup is started again.');
        $this->expire();

        // Confirmed: recorded, then deleted.
        $this->backupOp = ['kind' => 'backup', 'state' => 'done', 'snapshot' => 'a1b2c3d4', 'started' => now()->toIso8601String()];
        $this->expire();
        $this->assertTrue($called('DELETE /v1/sites/paid-shop'), json_encode($this->calls));
        $this->assertSame(0, Site::count());
        $this->assertDatabaseHas('audit_events', ['action' => 'site.final_backup']);
    }
}
