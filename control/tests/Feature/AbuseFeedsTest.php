<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** abuse:feeds - our sites in public threat feeds (audit A6). */
class AbuseFeedsTest extends TestCase
{
    private array $paused = [];

    private string $urlhaus = '';

    private string $openphish = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't'],
            'fleet.zone' => 'codeinchrome.com', 'fleet.safe_browsing_key' => null,
            'fleet.threat_feeds' => ['URLhaus (malware)' => 'https://feeds.test/urlhaus', 'OpenPhish (phishing)' => 'https://feeds.test/openphish']]);
        Http::fake([
            'feeds.test/urlhaus' => fn () => Http::response($this->urlhaus),
            'feeds.test/openphish' => fn () => Http::response($this->openphish),
            '127.0.0.1:9441/*' => function (ClientRequest $r) {
                if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/suspended')) {
                    $this->paused[] = explode('/', parse_url($r->url(), PHP_URL_PATH))[3];
                }

                return Http::response(['ok' => true, 'applied' => []]);
            },
        ]);
    }

    private function site(string $id, string $plan = 'free'): Site
    {
        return Site::create(['user_id' => User::factory()->create(['plan' => $plan])->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20800 + Site::count()]);
    }

    public function test_a_free_site_in_a_feed_is_paused_once_and_a_paid_one_goes_to_review(): void
    {
        $free = $this->site('kit');
        $paid = $this->site('shop', 'starter');
        $clean = $this->site('clean');
        $this->urlhaus = "# comment\nhttp://1.2.3.4/bin.sh\nhttps://kit.codeinchrome.com/files/payload.exe\n";
        $this->openphish = "https://SHOP.codeinchrome.com/login/\nhttps://notours.codeinchrome.com.evil.test/x\n";

        $this->artisan('abuse:feeds')->assertSuccessful();
        $this->assertSame(['kit'], $this->paused, 'only the free site is paused');
        $this->assertSame('suspended', $free->fresh()->status);
        $this->assertSame('live', $paid->fresh()->status);
        $this->assertSame('live', $clean->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'abuse.review', 'site' => 'kit']);
        $this->assertDatabaseHas('audit_events', ['action' => 'abuse.review', 'site' => 'shop']);

        // The same listing an hour later: already acted on this week.
        $this->artisan('abuse:feeds')->assertSuccessful();
        $this->assertSame(1, \App\Models\AuditEvent::where('action', 'abuse.review')->where('site', 'shop')->count());
    }

    public function test_a_customers_own_domain_is_matched_too(): void
    {
        $site = $this->site('bakery');
        SiteDomain::create(['site_id' => $site->id, 'domain' => 'bakery-example.test', 'token' => str_repeat('a', 32), 'verified_at' => now()]);
        // Claimed but never verified: anyone can claim a name they do not own.
        $other = $this->site('claimer');
        SiteDomain::create(['site_id' => $other->id, 'domain' => 'real-bank.test', 'token' => str_repeat('b', 32)]);
        $this->openphish = "https://bakery-example.test/verify\nhttps://real-bank.test/login\n";
        $this->artisan('abuse:feeds')->assertSuccessful();
        $this->assertSame(['bakery'], $this->paused, 'an unverified claim of a listed name is not the site');
    }

    public function test_the_dashboard_in_a_feed_is_an_alarm_not_a_pause(): void
    {
        config(['app.url' => 'https://app.codeinchrome.com']);
        $this->openphish = "https://app.codeinchrome.com/login\n";
        $this->artisan('abuse:feeds')->expectsOutputToContain('PLATFORM LISTED')->assertSuccessful();
        $this->assertSame([], $this->paused);
    }

    public function test_feeds_that_cannot_be_read_fail_the_run(): void
    {
        config(['fleet.threat_feeds' => ['down' => 'https://down.test/feed']]);
        Http::fake(['down.test/*' => Http::response('', 503)]);
        $this->artisan('abuse:feeds')->assertFailed();
    }
}
