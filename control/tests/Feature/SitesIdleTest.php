<?php

namespace Tests\Feature;

use App\Fleet\Stock;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SiteIdleNotice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Free sites with no visitors and no edits for 30 days are warned, then
 * paused, and come back with one click (the owner's decision, 2026-09-25).
 */
class SitesIdleTest extends TestCase
{
    /** @var array<string, int> site => unix time of its last human visit, as the agent reports it */
    private array $visits = [];

    /** @var list<string> "site:true|false" for every suspend call */
    private array $suspends = [];

    /** Only /v1/visits fails: pausing would still work, so nothing but the rule stops it. */
    private bool $visitsDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->freezeSecond();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't'],
            'fleet.admin_emails' => ['ops@example.com'],
        ]);
        Stock::remember('h1', ['cpus' => 16, 'memTotalBytes' => 64 * 1024 ** 3, 'diskTotalBytes' => 500 * 1024 ** 3]);
        Http::fake(['127.0.0.1:9441/*' => function (ClientRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/visits' && $this->visitsDown) {
                throw new ConnectionException('log unreadable');
            }
            if ($path === '/v1/visits') {
                return Http::response(['ok' => true, 'sites' => collect($this->visits)->map(fn ($t, $s) => ['site' => $s, 'last' => $t])->values()->all()]);
            }
            if (str_ends_with($path, '/suspended')) {
                $this->suspends[] = explode('/', $path)[3].':'.json_encode($r['suspended']);
            }

            return Http::response(['ok' => true, 'applied' => [], 'entries' => [], 'path' => '']);
        }]);
    }

    private function site(string $id, string $plan = 'free', int $ageDays = 60, string $email = ''): Site
    {
        $user = User::factory()->create(['plan' => $plan] + ($email ? ['email' => $email] : []));

        return Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
            'status' => 'live', 'port' => 20000 + Site::count(), 'cpu_limit' => '0.5', 'memory_limit' => '384m',
            'provisioned_at' => now()->subDays($ageDays)]);
    }

    public function test_a_long_idle_site_is_warned_first_and_paused_only_three_days_later(): void
    {
        $site = $this->site('quiet');

        $this->artisan('sites:idle')->assertSuccessful();
        $this->assertSame('live', $site->fresh()->status, 'never paused without a warning first');
        Notification::assertSentTo($site->user, SiteIdleNotice::class, fn ($n) => $n->kind === 'warning'
            && $n->when->equalTo(now()->addDays(3)));

        $this->travel(2)->days();
        $this->artisan('sites:idle')->assertSuccessful();
        $this->assertSame('live', $site->fresh()->status);
        Notification::assertSentToTimes($site->user, SiteIdleNotice::class, 1);

        $this->travel(1)->days();
        $this->artisan('sites:idle')->assertSuccessful();
        $this->assertSame(['quiet:true'], $this->suspends);
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertSame('idle', $site->fresh()->paused_reason);
        Notification::assertSentTo($site->user, SiteIdleNotice::class, fn ($n) => $n->kind === 'paused');
    }

    public function test_a_site_under_27_days_old_or_idle_is_left_alone(): void
    {
        $young = $this->site('young', ageDays: 26);
        $visited = $this->site('visited');
        $this->visits = ['visited' => now()->subDays(10)->getTimestamp()];
        $worked = $this->site('worked');
        $worked->update(['last_worked_at' => now()->subDays(5)]);

        $this->artisan('sites:idle')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(now()->subDays(10)->getTimestamp(), $visited->fresh()->last_visit_at->getTimestamp());
        $this->assertSame([], $this->suspends);
    }

    public function test_a_visit_after_the_warning_cancels_the_pause(): void
    {
        $site = $this->site('quiet');
        $this->artisan('sites:idle');
        $this->travel(1)->days();
        $this->visits = ['quiet' => now()->getTimestamp()];

        $this->travel(5)->days();
        $this->artisan('sites:idle')->assertSuccessful();

        $this->assertSame('live', $site->fresh()->status);
        $this->assertSame([], $this->suspends);
    }

    public function test_paid_and_operator_sites_are_never_touched(): void
    {
        $this->site('paid-site', plan: 'starter');
        $ops = $this->site('ops-site', email: 'ops@example.com');
        $ops->user->forceFill(['email_verified_at' => now()])->save();

        $this->artisan('sites:idle')->assertSuccessful();
        $this->travel(4)->days();
        $this->artisan('sites:idle')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([], $this->suspends);
    }

    public function test_a_host_that_cannot_report_visits_pauses_nothing(): void
    {
        $site = $this->site('quiet');
        $site->update(['idle_warned_at' => now()->subDays(5)]);
        $this->visitsDown = true;

        $this->artisan('sites:idle')->assertSuccessful();

        $this->assertSame('live', $site->fresh()->status);
        $this->assertSame([], $this->suspends);
        Notification::assertNothingSent();
    }

    public function test_working_on_a_site_counts_as_activity_and_clears_the_warning(): void
    {
        $site = $this->site('quiet');
        $site->update(['idle_warned_at' => now()->subDay()]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson(route('files.index', $site))->assertNotFound();
        $this->assertNull($site->fresh()->last_worked_at, 'someone else\'s request is not the owner working');

        $this->actingAs($site->user)->getJson(route('files.index', $site))->assertOk();
        $this->assertEquals(now(), $site->fresh()->last_worked_at);
        $this->assertNull($site->fresh()->idle_warned_at);
    }

    public function test_the_owner_brings_an_idle_site_back_with_one_click(): void
    {
        $site = $this->site('quiet');
        $site->update(['status' => 'suspended', 'paused_reason' => 'idle', 'idle_warned_at' => now()->subDays(4)]);

        // Reading files stays open on a paused site; building on it does not.
        $this->actingAs($site->user)->putJson(route('files.store', $site), ['path' => '/routes/web.php', 'content' => '<?php'])
            ->assertStatus(423)->assertJson(['reason' => 'idle']);

        $this->actingAs($site->user)->post(route('sites.wake', $site))->assertRedirect(route('dashboard'));

        $site->refresh();
        $this->assertSame('live', $site->status);
        $this->assertNull($site->paused_reason);
        $this->assertEquals(now(), $site->last_worked_at, 'not re-paused by the next run');
        $this->assertSame(['quiet:false'], $this->suspends);
    }

    public function test_one_click_never_undoes_a_trial_cpu_or_abuse_pause_or_someone_elses_site(): void
    {
        foreach (['trial', 'cpu', 'egress', 'abuse'] as $reason) {
            $site = $this->site("paused-$reason");
            $site->update(['status' => 'suspended', 'paused_reason' => $reason]);
            $this->actingAs($site->user)->post(route('sites.wake', $site))->assertSessionHas('error');
            $this->assertSame('suspended', $site->fresh()->status, $reason);
        }

        $idle = $this->site('idle-one');
        $idle->update(['status' => 'suspended', 'paused_reason' => 'idle']);
        $this->actingAs(User::factory()->create())->post(route('sites.wake', $idle))->assertNotFound();

        $idle->user->forceFill(['banned_at' => now()])->save();
        $this->actingAs($idle->user->fresh())->post(route('sites.wake', $idle));
        $this->assertSame('suspended', $idle->fresh()->status, 'a ban is lifted only by abuse:unban');
        $this->assertSame([], $this->suspends);
    }

    public function test_the_fleets_own_addresses_are_not_counted_as_visitors(): void
    {
        // The link scanner's renderer reads sites like a browser, from a
        // fleet host: the agent is told those addresses so they are no visit.
        $this->site('quiet');
        $this->artisan('sites:idle')->assertSuccessful();
        Http::assertSent(fn (ClientRequest $r) => parse_url($r->url(), PHP_URL_PATH) === '/v1/visits'
            && str_contains((string) parse_url($r->url(), PHP_URL_QUERY), 'ignore=10.0.0.1'));
    }
}

