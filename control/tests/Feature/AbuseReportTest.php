<?php

namespace Tests\Feature;

use App\Models\AbuseReport;
use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** Anyone can report a hosted site; the owner hears of it at once. */
class AbuseReportTest extends TestCase
{
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true]);
        Event::listen(MessageSent::class, fn ($e) => $this->sent[] = $e->message);
        $user = User::factory()->create(['email' => 'shady@gmail.com']);
        Site::create(['user_id' => $user->id, 'site_id' => 'bank-login', 'domain' => 'bank-login.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20200]);
        $this->sent = []; // the new-site email (OwnerNotifier) is not what these tests are about
    }

    public function test_a_report_about_a_hosted_site_is_saved_and_mailed_to_the_owner(): void
    {
        $this->get('/report?url=https://bank-login.codeinchrome.com/signin')->assertOk()
            ->assertSee('value="https://bank-login.codeinchrome.com/signin"', false);

        $this->post('/report', ['url' => 'https://bank-login.codeinchrome.com/signin', 'reason' => 'phishing',
            'details' => 'Looks like my bank\'s login.', 'email' => 'visitor@example.com'])
            ->assertRedirect(route('report'))->assertSessionHas('status');

        $report = AbuseReport::first();
        $this->assertSame('phishing', $report->reason);
        $this->assertSame('bank-login.codeinchrome.com', $report->site->domain);
        $this->assertNotNull($report->reporter_hash);
        $this->assertNotSame(request()->ip(), $report->reporter_hash, 'the IP itself is never kept');

        $this->assertCount(1, $this->sent);
        $this->assertSame('[codeinchrome] Abuse report (phishing): bank-login.codeinchrome.com', $this->sent[0]->getSubject());
        $body = $this->sent[0]->getTextBody();
        $this->assertStringContainsString('Account: shady@gmail.com', $body);
        $this->assertStringContainsString('abuse:ban shady@gmail.com', $body);
    }

    public function test_an_address_we_do_not_host_or_a_bad_reason_is_refused(): void
    {
        $this->post('/report', ['url' => 'https://example.org/x', 'reason' => 'phishing'])->assertSessionHasErrors(['url']);
        $this->post('/report', ['url' => 'https://bank-login.codeinchrome.com/', 'reason' => 'nonsense'])->assertSessionHasErrors(['reason']);
        $this->post('/report', ['url' => 'javascript:alert(1)', 'reason' => 'phishing'])->assertSessionHasErrors(['url']);
        $this->assertSame(0, AbuseReport::count());
        $this->assertCount(0, $this->sent);
    }

    public function test_reports_are_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/report', ['url' => 'https://bank-login.codeinchrome.com/', 'reason' => 'spam'])->assertRedirect();
        }
        $this->post('/report', ['url' => 'https://bank-login.codeinchrome.com/', 'reason' => 'spam'])->assertStatus(429);
        $this->assertSame(3, AbuseReport::count());
    }

    public function test_the_terms_forbid_what_hosts_and_cdns_forbid_and_link_the_report_form(): void
    {
        $this->get('/terms')->assertOk()
            ->assertSee('pornographic or sexually explicit content')
            ->assertSee('reported to the authorities')
            ->assertSee('gambling')
            ->assertSee('encrypted, encoded or obfuscated code')
            ->assertSee('open redirectors')
            ->assertSee('href="'.route('report').'"', false);
        $this->get('/')->assertSee('href="'.route('report').'"', false);
    }
}
