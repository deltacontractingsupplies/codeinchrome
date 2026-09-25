<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** sites:indexing lets search engines in once a free site's first week is over. */
class SitesIndexingTest extends TestCase
{
    /** @var array<string, bool> site => the noIndex value the agent was sent */
    private array $sent = [];

    private bool $hostDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.tokens' => ['h1' => 't']]);
        $this->travelTo('2026-09-25 12:00:00');
        Http::fake(['127.0.0.1:9441/v1/sites/*/indexing' => function (ClientRequest $r) {
            if ($this->hostDown) {
                throw new ConnectionException('tunnel down');
            }
            $this->sent[explode('/', parse_url($r->url(), PHP_URL_PATH))[3]] = $r['noIndex'];

            return Http::response(['ok' => true, 'noIndex' => $r['noIndex']]);
        }]);
    }

    private function site(string $id, string $plan, ?string $until, string $status = 'live'): Site
    {
        return Site::create(['user_id' => User::factory()->create(['plan' => $plan])->id, 'site_id' => $id,
            'domain' => "$id.codeinchrome.com", 'host' => 'h1', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'status' => $status, 'port' => 20000 + count($this->sent),
            'noindex_until' => $until, 'scanned_clean_at' => now()->subHour(), 'links_clean_at' => now()->subHour()]);
    }

    public function test_a_week_old_site_is_opened_and_a_younger_one_stays_hidden(): void
    {
        $old = $this->site('week-old', 'free', '2026-09-25 11:00:00');
        $young = $this->site('day-old', 'free', '2026-10-01 12:00:00');
        $never = $this->site('indexed', 'free', null);

        $this->artisan('sites:indexing')->assertSuccessful();

        $this->assertSame(['week-old' => false, 'day-old' => true], $this->sent,
            'the young site is re-sent true, in case its host missed it; a site never hidden is not touched');
        $this->assertNull($old->fresh()->noindex_until);
        $this->assertNotNull($young->fresh()->noindex_until);
        $this->assertNull($never->fresh()->noindex_until);
    }

    public function test_an_account_that_became_paid_is_opened_at_once(): void
    {
        $site = $this->site('upgraded', 'starter', '2026-10-01 12:00:00');

        $this->artisan('sites:indexing')->assertSuccessful();

        $this->assertSame(['upgraded' => false], $this->sent);
        $this->assertNull($site->fresh()->noindex_until);
    }

    public function test_an_unreachable_host_keeps_the_date_so_the_next_run_retries(): void
    {
        $site = $this->site('week-old', 'free', '2026-09-25 11:00:00');
        $this->hostDown = true;

        $this->artisan('sites:indexing')->assertFailed();

        $this->assertNotNull($site->fresh()->noindex_until);
    }

    public function test_a_paused_site_is_left_until_it_is_live_again(): void
    {
        $site = $this->site('paused', 'free', '2026-09-25 11:00:00', 'suspended');

        $this->artisan('sites:indexing')->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertNotNull($site->fresh()->noindex_until);
    }

    public function test_a_site_under_review_stays_hidden_after_its_week(): void
    {
        $site = $this->site('flagged', 'free', '2026-09-25 11:00:00');
        $site->update(['links_clean_at' => null]); // the link check found something for a person to look at

        $this->artisan('sites:indexing')->assertSuccessful();

        $this->assertSame(['flagged' => true], $this->sent, 'kept hidden (and re-sent, in case the host missed it)');
        $this->assertNotNull($site->fresh()->noindex_until);
    }
}
