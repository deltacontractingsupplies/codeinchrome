<?php

namespace Tests\Feature;

use App\Console\Commands\AbuseDns;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** abuse:dns - what sites looked up, from the hosts' DNS forwarders. */
class AbuseDnsTest extends TestCase
{
    private array $lookups = [];

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Http::fake(['127.0.0.1:9441/v1/dns*' => function () {
            if ($this->down) {
                throw new ConnectionException('tunnel down');
            }

            return Http::response(['ok' => true, 'sites' => $this->lookups]);
        }]);
    }

    private function site(string $id): Site
    {
        return Site::create(['user_id' => User::factory()->create()->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20700 + Site::count()]);
    }

    public function test_watched_names_match_by_suffix_and_nothing_else(): void
    {
        $this->assertNotNull(AbuseDns::watched('api.telegram.org'));
        $this->assertNotNull(AbuseDns::watched('API.Telegram.org.'));
        $this->assertNotNull(AbuseDns::watched('abc123.ngrok-free.app'));
        $this->assertNotNull(AbuseDns::watched('xmr.supportxmr.com'));
        $this->assertNull(AbuseDns::watched('telegram.org'), 'the website is not the bot API');
        $this->assertNull(AbuseDns::watched('notdiscord.com'));
        $this->assertNull(AbuseDns::watched('api.stripe.com'));
    }

    public function test_a_site_that_looked_up_a_watched_name_goes_to_review_once_a_week_and_is_never_banned(): void
    {
        $kit = $this->site('kit');
        $shop = $this->site('shop');
        $this->lookups = ['kit' => ['api.telegram.org' => 3, 'fonts.googleapis.com' => 1], 'shop' => ['api.stripe.com' => 5], 'gone' => ['pastebin.com' => 1]];

        $this->artisan('abuse:dns')->expectsOutputToContain('kit: api.telegram.org')->assertSuccessful();
        $this->assertDatabaseHas('audit_events', ['action' => 'abuse.review', 'site' => 'kit']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'abuse.review', 'site' => 'shop']);
        $this->assertNull($kit->user->fresh()->banned_at);
        $this->assertSame('live', $kit->fresh()->status);

        $this->artisan('abuse:dns')->assertSuccessful();
        $this->assertSame(1, \App\Models\AuditEvent::where('action', 'abuse.review')->where('site', 'kit')->count(), 'told once, not every hour');
        $this->travel(8)->days();
        $this->artisan('abuse:dns')->assertSuccessful();
        $this->assertSame(2, \App\Models\AuditEvent::where('action', 'abuse.review')->where('site', 'kit')->count(), 'and again after a week');
    }

    public function test_a_host_that_cannot_answer_fails_the_run(): void
    {
        $this->down = true;
        $this->artisan('abuse:dns')->assertFailed();
    }
}
