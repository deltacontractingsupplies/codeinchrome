<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Malware or obfuscated PHP bans the account and takes its sites down
 * (owner's decision, 2026-09-25; App\Abuse\Enforcer).
 */
class AbuseEnforcementTest extends TestCase
{
    private array $suspended = [];

    private string $scanAnswer = 'clean';

    private function siteFor(User $user, string $id, int $port): Site
    {
        return Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => $port]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't']]);
        Http::fake(['127.0.0.1:9441/*' => function (ClientRequest $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/suspended')) {
                $this->suspended[] = explode('/', $path)[3].':'.json_encode($r['suspended']);

                return Http::response(['ok' => true, 'applied' => []]);
            }
            if ($r->method() === 'PUT' && str_ends_with($path, '/files')) {
                return Http::response(['ok' => false, 'error' => 'malware', 'hint' => 'refused: /public/s.php holds code written to hide what it does',
                    'findings' => [['path' => '/public/s.php', 'kind' => 'obfuscated', 'detail' => 'request input handed to a shell']]], 422);
            }
            if (str_ends_with($path, '/scan')) {
                return match ($this->scanAnswer) {
                    'clean' => Http::response(['ok' => true, 'findings' => [], 'clean' => true]),
                    'infected' => Http::response(['ok' => true, 'findings' => [['path' => '/public/x.exe', 'kind' => 'malware', 'detail' => 'Win.Trojan.Agent']], 'clean' => false]),
                    'broken' => Http::response(['ok' => false, 'error' => 'scan_failed', 'hint' => 'clamd down'], 500),
                };
            }

            return Http::response(['ok' => true]);
        }]);
    }

    public function test_a_refused_malware_save_bans_the_account_and_takes_every_site_down(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'bad@gmail.com', 'plan' => 'free']);
        $site = $this->siteFor($user, 'kit', 20100);
        $other = $this->siteFor($user, 'kit2', 20101);

        $this->actingAs($user)->putJson(route('files.store', $site), ['path' => '/public/s.php', 'content' => '<?php system($_GET["c"]);'])
            ->assertForbidden()->assertJson(['ok' => false, 'error' => 'malware']);

        $user->refresh();
        $this->assertNotNull($user->banned_at);
        $this->assertStringContainsString('request input handed to a shell', $user->banned_reason);
        $this->assertEqualsCanonicalizing(['kit:true', 'kit2:true'], $this->suspended, 'every site of the account is taken down');
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertSame('suspended', $other->fresh()->status);
        $this->assertTrue(\App\Models\AuditEvent::where('action', 'abuse.banned')->exists());
    }

    public function test_a_banned_account_is_signed_out_and_cannot_come_back_in(): void
    {
        $user = User::factory()->create(['email' => 'gone@gmail.com', 'password' => bcrypt('long-enough-password'), 'banned_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->post('/login', ['email' => 'gone@gmail.com', 'password' => 'long-enough-password'])->assertSessionHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_a_payment_never_brings_a_banned_accounts_sites_back(): void
    {
        $user = User::factory()->create(['plan' => 'starter', 'banned_at' => now(), 'suspended_at' => now()]);
        $site = $this->siteFor($user, 'kept-down', 20102);
        $site->update(['status' => 'suspended']);

        app(\App\Fleet\Suspension::class)->resumeAll($user);
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertSame([], $this->suspended, 'the host was never asked to resume it');
    }

    public function test_the_scheduled_scan_bans_on_a_finding_and_never_reads_a_failure_as_clean(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $this->siteFor($user, 'scanned', 20103);

        $this->scanAnswer = 'broken';
        $this->artisan('abuse:scan')->assertFailed();
        $this->assertNull($user->fresh()->banned_at, 'a scan that could not run bans no one');

        $this->scanAnswer = 'clean';
        $this->artisan('abuse:scan')->expectsOutputToContain('1 clean')->assertSuccessful();
        $this->assertNull($user->fresh()->banned_at);

        $this->scanAnswer = 'infected';
        $this->artisan('abuse:scan')->expectsOutputToContain('1 flagged')->assertSuccessful();
        $this->assertStringContainsString('Win.Trojan.Agent', $user->fresh()->banned_reason);
    }

    public function test_the_owner_can_reverse_a_ban_and_the_sites_come_back(): void
    {
        $user = User::factory()->create(['email' => 'mistake@gmail.com', 'banned_at' => now(), 'banned_reason' => 'x']);
        $site = $this->siteFor($user, 'backup', 20104);
        $site->update(['status' => 'suspended']);

        $this->artisan('abuse:unban', ['email' => 'mistake@gmail.com'])->assertSuccessful();
        $this->assertNull($user->fresh()->banned_at);
        $this->assertSame('live', $site->fresh()->status);
        $this->assertSame(['backup:false'], $this->suspended);
    }

    public function test_the_operator_can_ban_by_hand_but_must_say_why(): void
    {
        $user = User::factory()->create(['email' => 'phish@gmail.com']);
        $this->artisan('abuse:ban', ['email' => 'phish@gmail.com'])->assertFailed();
        $this->assertNull($user->fresh()->banned_at);
        $this->artisan('abuse:ban', ['email' => 'phish@gmail.com', '--reason' => 'phishing page for a bank'])->assertSuccessful();
        $this->assertStringContainsString('phishing page for a bank', $user->fresh()->banned_reason);
    }
}
