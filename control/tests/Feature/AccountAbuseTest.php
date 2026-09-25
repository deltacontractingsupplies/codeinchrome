<?php

namespace Tests\Feature;

use App\Auth\ClientNet;
use App\Fleet\Provisioner;
use App\Models\AbuseReport;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Coming back after a pause or a ban, and reports that are acted on (the second security audit, 2026-09-25). */
class AccountAbuseTest extends TestCase
{
    private array $sent = [];

    private array $paused = [];

    private string $page = '<p>ok</p>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true,
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Event::listen(MessageSent::class, fn ($e) => $this->sent[] = $e->message->getSubject());
        Http::fake([
            '127.0.0.1:9441/*' => function (ClientRequest $r) {
                if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/suspended')) {
                    $this->paused[] = explode('/', parse_url($r->url(), PHP_URL_PATH))[3];
                }

                return Http::response(['ok' => true, 'applied' => []]);
            },
            '*' => fn () => Http::response($this->page, 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function site(User $user, string $id, string $status = 'live', ?string $reason = null): Site
    {
        return Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
            'status' => $status, 'paused_reason' => $reason, 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20600 + Site::count()]);
    }

    public function test_an_ipv6_visitor_is_one_client_per_64_and_the_signal_is_a_hash_of_the_wider_network(): void
    {
        $this->assertSame(ClientNet::key('2a00:1851:1e:df01::1'), ClientNet::key('2a00:1851:1e:df01:ffff::9'));
        $this->assertNotSame(ClientNet::key('2a00:1851:1e:df01::1'), ClientNet::key('2a00:1851:1e:df02::1'));
        $this->assertSame('203.0.113.9', ClientNet::key('203.0.113.9'));
        $this->assertNotSame(ClientNet::signal('203.0.113.9'), ClientNet::signal('203.0.113.200'), 'not a whole /24: a carrier or office');
        $this->assertSame(ClientNet::signal('2a00:1851:1e:df01::1'), ClientNet::signal('2a00:1851:1e:df01:ffff::2'), 'one IPv6 /64');
        $this->assertStringNotContainsString('203', (string) ClientNet::signal('203.0.113.9'), 'never the address itself');
    }

    public function test_a_site_paused_by_the_abuse_checks_cannot_be_deleted_to_start_over(): void
    {
        $user = User::factory()->create();
        $cpu = $this->site($user, 'miner', 'suspended', 'cpu');
        $this->actingAs($user)->deleteJson(route('sites.destroy', $cpu))->assertStatus(423)->assertJsonPath('reason', 'cpu');
        $this->assertNotNull($cpu->fresh(), 'still there');

        $this->expectExceptionMessage('paused by our abuse checks');
        Provisioner::make()->provision($user, 'fresh-start');
    }

    public function test_an_idle_pause_can_still_be_deleted(): void
    {
        $user = User::factory()->create();
        $idle = $this->site($user, 'sleepy', 'suspended', 'idle');
        // Reaches the controller (the delete itself is ProvisioningTest's): not the 423 of an abuse pause.
        $this->assertNotSame(423, $this->actingAs($user)->deleteJson(route('sites.destroy', $idle))->status());
    }

    public function test_the_account_cannot_be_deleted_while_a_site_is_abuse_paused(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct horse battery 9')]);
        $this->site($user, 'egressy', 'suspended', 'egress');
        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'correct horse battery 9'])->assertSessionHas('error');
        $this->assertNotNull($user->fresh());
    }

    public function test_a_signup_from_a_recently_banned_accounts_network_is_held_before_it_can_create_a_site(): void
    {
        $net = ClientNet::signal('198.51.100.20');
        User::factory()->create(['email' => 'banned@gmail.com'])->forceFill(['signup_net' => $net, 'banned_at' => now()->subDays(3)])->save();
        $again = User::factory()->create(['email' => 'again@gmail.com']);
        $again->forceFill(['signup_net' => $net])->save();

        foreach ([1, 2] as $try) {
            try {
                Provisioner::make()->provision($again, "try-$try");
                $this->fail('must be held');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('being checked', $e->getMessage());
            }
        }
        $this->assertSame(['[codeinchrome] Account held for review: again@gmail.com'], $this->sent, 'the owner is told once');
    }

    public function test_a_second_automatic_pause_in_30_days_is_a_ban(): void
    {
        $user = User::factory()->create();
        $site = $this->site($user, 'repeat');
        \App\Audit\Audit::record('abuse.cpu_paused', $user, $site, detail: []);
        app(\App\Abuse\Enforcer::class)->escalateRepeatPause($user->fresh(), 'CPU');
        $this->assertNull($user->fresh()->banned_at, 'a first pause is a pause');

        $this->travel(2)->days();
        \App\Audit\Audit::record('abuse.scan_paused', $user, $site, detail: []);
        app(\App\Abuse\Enforcer::class)->escalateRepeatPause($user->fresh(), 'scanning');
        $this->assertNotNull($user->fresh()->banned_at);
    }

    public function test_three_reporters_in_a_day_pause_the_site_and_a_report_triggers_a_check(): void
    {
        $site = $this->site(User::factory()->create(['email' => 'reported@gmail.com']), 'reported');
        foreach (['2a00:1851:1e:df01::1', '2a00:1851:1e:df01::2', '198.51.100.1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/report', ['url' => 'https://reported.codeinchrome.com/', 'reason' => 'phishing']);
        }
        $this->assertSame(3, AbuseReport::count());
        $this->assertSame([], $this->paused, 'the two IPv6 addresses share a /64: one reporter, so two in all');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('/report', ['url' => 'https://reported.codeinchrome.com/', 'reason' => 'phishing']);
        $this->assertSame(['reported'], $this->paused);
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertNull($site->user->fresh()->banned_at, 'paused, not banned');
    }

    public function test_a_report_of_a_clickfix_page_bans_after_the_check(): void
    {
        $site = $this->site(User::factory()->create(['email' => 'clickfix@gmail.com']), 'verify-human');
        $this->page = '<h1>Verify you are human</h1><button onclick="navigator.clipboard.writeText(\'powershell -w hidden -enc SQBFAFgA\')">OK</button><p>Press Windows + R, then Ctrl + V.</p>';

        $this->post('/report', ['url' => 'https://verify-human.codeinchrome.com/', 'reason' => 'malware']);

        $this->assertNotNull($site->user->fresh()->banned_at);
    }

    public function test_the_test_suites_own_accounts_neither_cause_nor_suffer_a_hold(): void
    {
        config(['signup.test_domain' => 'codeinchrome.test']);
        $net = ClientNet::signal('198.51.100.20');
        User::factory()->create(['email' => 'banned-run@codeinchrome.test'])->forceFill(['signup_net' => $net, 'banned_at' => now()])->save();
        $next = User::factory()->create(['email' => 'next-run@codeinchrome.test']);
        $next->forceFill(['signup_net' => $net])->save();
        $person = User::factory()->create(['email' => 'person@gmail.com']);
        $person->forceFill(['signup_net' => $net])->save();

        foreach ([$next, $person] as $user) {
            try {
                Provisioner::make()->provision($user, 'x-'.$user->id);
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString('being checked', $e->getMessage(), $user->email.' must not be held');
            }
        }
        $this->assertSame([], array_values(array_filter($this->sent, fn ($s) => str_contains($s, 'held for review'))));
    }

    public function test_sign_up_is_capped_per_network_but_not_for_the_signed_test_suite(): void
    {
        config(['signup.test_domain' => 'codeinchrome.test', 'signup.test_secret' => 'shh']);
        $form = fn ($n) => ['name' => 'N', 'email' => "cap$n@codeinchrome.test", 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9'];
        $codes = [];
        for ($i = 0; $i < 25; $i++) {
            \Illuminate\Support\Facades\RateLimiter::clear('198.51.100.9'); // step past the per-minute cap: this is about the hourly one
            $codes[] = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->post('/register', ['name' => 'N', 'email' => "p$i@example.org",
                'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9'])->status();
            auth()->logout();
        }
        $this->assertContains(429, $codes, 'the hourly cap bites for anyone');
        $this->withHeader('X-CIC-E2E', \App\Auth\TestSuite::header('shh'))->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->post('/register', $form(1))->assertSessionHasNoErrors()->assertStatus(302);
    }
}
