<?php

namespace Tests\Feature;

use App\Fleet\Monitoring;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    private int $siteStatus = 200;

    private bool $siteDown = false;

    private bool $agentDown = false;

    private float $diskFree = 0.5;

    private array $alerts = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't'],
            'fleet.alert_webhook' => 'https://hooks.example.test/alert',
        ]);
        Http::fake([
            '127.0.0.1:944*/v1/host/stats' => function () {
                if ($this->agentDown) {
                    throw new \Illuminate\Http\Client\ConnectionException('tunnel down');
                }

                return Http::response(['ok' => true, 'stats' => [
                    'diskFreeBytes' => (int) (100e9 * $this->diskFree), 'diskTotalBytes' => (int) 100e9,
                    'memAvailableBytes' => 8e9, 'memTotalBytes' => 16e9, 'load1' => 0.4, 'cpus' => 4,
                    'mysqlUp' => true, 'caddyUp' => true, 'sitesNotRunning' => [], 'disksUnmounted' => [],
                ]]);
            },
            'shop.codeinchrome.com*' => function () {
                if ($this->siteDown) {
                    throw new \Illuminate\Http\Client\ConnectionException('connection refused');
                }

                return Http::response('ok', $this->siteStatus);
            },
            'hooks.example.test/*' => function ($r) {
                $this->alerts[] = $r['text'];

                return Http::response('ok');
            },
        ]);
        Site::create(['user_id' => User::factory()->create()->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
    }

    private function tick(): void
    {
        app(Monitoring::class)->run();
    }

    public function test_one_failure_is_not_an_outage_two_are_and_recovery_closes_it(): void
    {
        $this->tick();
        $this->siteDown = true;
        $this->tick();
        $this->assertSame(0, Incident::count(), 'A single failed check opened an incident.');
        $this->assertSame([], $this->alerts);

        $this->tick();
        $this->assertSame(1, Incident::whereNull('resolved_at')->count());
        $this->assertCount(1, $this->alerts);
        $this->assertStringContainsString('DOWN: site shop.codeinchrome.com', $this->alerts[0]);

        // Still down: no second incident, no alert per check.
        $this->tick();
        $this->tick();
        $this->assertSame(1, Incident::count());
        $this->assertCount(1, $this->alerts);

        $this->siteDown = false;
        $this->tick();
        $this->assertSame(0, Incident::whereNull('resolved_at')->count());
        $this->assertCount(2, $this->alerts);
        $this->assertStringContainsString('RECOVERED', $this->alerts[1]);
    }

    public function test_a_404_is_the_app_answering_not_an_outage_but_a_500_is(): void
    {
        $this->siteStatus = 404;
        $this->tick();
        $this->tick();
        $this->assertSame(0, Incident::count());

        $this->siteStatus = 502;
        $this->tick();
        $this->tick();
        $this->assertSame(1, Incident::where('monitor_key', 'site:shop')->count());
    }

    public function test_an_unreachable_agent_and_a_nearly_full_disk_each_raise_an_incident(): void
    {
        $this->diskFree = 0.04;
        $this->tick();
        $this->tick();
        $this->assertNotNull(Incident::where('monitor_key', 'host:h1:disk')->first());

        $this->agentDown = true;
        $this->tick();
        $this->tick();
        $this->assertNotNull(Incident::where('monitor_key', 'host:h1')->first());
    }

    public function test_an_undelivered_alert_is_recorded_as_such(): void
    {
        config(['fleet.alert_webhook' => null]);
        $this->siteDown = true;
        $this->tick();
        $this->tick();
        $this->assertFalse(Incident::first()->alerted, 'An incident claimed an alert that was never sent.');
    }

    public function test_without_a_webhook_the_alert_goes_to_the_operators_by_email(): void
    {
        config(['fleet.alert_webhook' => null, 'fleet.mail_enabled' => true, 'fleet.admin_emails' => ['ops@codeinchrome.com']]);
        $this->siteDown = true;
        $this->tick();
        $this->tick();

        // The array mailer keeps what would have been sent (Mail::fake does
        // not record Mail::raw).
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertTrue(Incident::first()->alerted);
        $this->assertCount(1, $sent);
        $this->assertSame('ops@codeinchrome.com', $sent[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertSame([], $this->alerts, 'The webhook was used although none is configured.');
    }

    public function test_the_status_page_is_for_operators_only(): void
    {
        $this->tick();
        config(['fleet.admin_emails' => ['ops@codeinchrome.com']]);

        $this->actingAs(User::factory()->create(['email' => 'someone@example.com']))->get('/status')->assertNotFound();
        // An operator's address registered by someone who cannot read its mail:
        // unverified, so not an operator.
        $claimed = User::factory()->create(['email' => 'ops@codeinchrome.com', 'email_verified_at' => null]);
        $this->assertFalse($claimed->isOperator());
        $this->actingAs($claimed)->get('/status')->assertNotFound();
        $claimed->delete();
        $this->actingAs(User::factory()->create(['email' => 'OPS@codeinchrome.com']))->get('/status')
            ->assertOk()->assertSee('site shop.codeinchrome.com');
    }

    public function test_a_deleted_sites_incident_is_closed_not_left_open_forever(): void
    {
        $this->siteDown = true;
        $this->tick();
        $this->tick();
        $this->assertSame(1, Incident::whereNull('resolved_at')->count());

        Site::where('site_id', 'shop')->delete();
        $this->tick();

        $this->assertSame(0, Incident::whereNull('resolved_at')->count());
        $this->assertStringContainsString('site was deleted', Incident::first()->detail);
        $this->assertNull(Monitor::where('key', 'site:shop')->first());
        // Host monitors are untouched.
        $this->assertNotNull(Monitor::where('key', 'host:h1')->first());
    }

    public function test_low_stock_is_alerted_once_and_its_recovery_too(): void
    {
        $monitor = app(\App\Fleet\Monitoring::class);
        config(['fleet.stock.alert_below' => 0]);
        $monitor->run(); // records the fake host's figures
        // However many Starters the fake host holds, set the bar just above it.
        $left = app(\App\Fleet\Stock::class)->available('starter');
        config(['fleet.stock.alert_below' => $left + 1]);
        $monitor->run();
        $monitor->run(); // opens the incident on the second failure, as for everything else
        $monitor->run();
        $low = array_values(array_filter($this->alerts, fn ($a) => str_contains($a, 'Starter stock')));
        $this->assertCount(1, $low, 'one alert, not one per check');
        $this->assertStringContainsString("only $left left - add a host with infra/add-host.sh", $low[0]);

        config(['fleet.stock.alert_below' => 3]); // a host was added
        $monitor->run();
        $this->assertStringContainsString('RECOVERED: Starter stock', collect($this->alerts)->last());
    }

    public function test_a_site_running_out_of_file_slots_is_alerted(): void
    {
        Site::where('site_id', 'shop')->update(['inodes_used' => 62000, 'inodes_total' => 65536]);
        $monitor = app(\App\Fleet\Monitoring::class);
        foreach (range(1, 3) as $check) {
            $monitor->run(); // an incident opens once the failure repeats, as for everything else
        }
        $files = array_values(array_filter($this->alerts, fn ($a) => str_contains($a, 'shop.codeinchrome.com files')));
        $this->assertCount(1, $files, 'one alert, not one per check');
        $this->assertStringContainsString('95% of its file slots used', $files[0]);

        // Its checks go with the site, the suffixed ones included.
        Site::where('site_id', 'shop')->delete();
        $monitor->run();
        $this->assertSame(0, \App\Models\Monitor::where('key', 'like', 'site:shop%')->count());
    }

    public function test_the_control_planes_own_backup_is_watched_by_its_stamp(): void
    {
        $monitor = app(\App\Fleet\Monitoring::class);
        // Not configured (development): not checked at all.
        config(['fleet.control_backup_stamp' => null]);
        $this->assertArrayNotHasKey('control:backup', $monitor->run());

        // Beside the repository, not in the system temp dir (the internal disk).
        $stamp = storage_path('framework/testing/control-backup-'.getmypid().'.ok');
        @unlink($stamp);
        config(['fleet.control_backup_stamp' => $stamp]);
        try {
            $this->assertFalse($monitor->run()['control:backup'][1], 'no stamp: no backup ever completed');
            $this->assertStringContainsString('no complete backup recorded', $monitor->run()['control:backup'][2]);

            touch($stamp, now()->subHours(2)->getTimestamp());
            $this->assertTrue($monitor->run()['control:backup'][1], 'two hours old is fine');

            // It once stopped for 32 hours on a stale lock with no alert.
            touch($stamp, now()->subHours(32)->getTimestamp());
            $check = $monitor->run()['control:backup'];
            $this->assertFalse($check[1]);
            $this->assertStringContainsString('newest complete backup 1 day ago', $check[2]);
            $this->assertNotNull(\App\Models\Monitor::where('key', 'control:backup')->first(), 'kept, never retired as a gone site');
        } finally {
            @unlink($stamp);
        }
    }

    public function test_a_site_whose_backups_stopped_is_alerted_once(): void
    {
        $monitor = app(\App\Fleet\Monitoring::class);
        $site = Site::where('site_id', 'shop')->first();

        // A new site is not held to it before its first night...
        $site->forceFill(['last_backup_at' => null])->save();
        $this->assertArrayNotHasKey('site:shop:backup', $monitor->run());
        // ...but is watched from its first backup on, however new.
        $site->forceFill(['last_backup_at' => now()->subHour()])->save();
        $this->assertTrue($monitor->run()['site:shop:backup'][1] ?? null);

        $site->forceFill(['created_at' => now()->subDays(3), 'last_backup_at' => now()->subHours(2)])->save();
        $this->assertTrue($monitor->run()['site:shop:backup'][1] ?? null, 'a backup two hours old is fine');

        $site->forceFill(['last_backup_at' => now()->subHours(40)])->save();
        foreach (range(1, 3) as $check) {
            $monitor->run();
        }
        $backups = array_values(array_filter($this->alerts, fn ($a) => str_contains($a, 'shop.codeinchrome.com backups')));
        $this->assertCount(1, $backups, 'one alert, not one per check');
        $this->assertStringContainsString('newest complete backup 1 day ago', $backups[0]);

        $site->forceFill(['last_backup_at' => null])->save();
        $this->assertStringContainsString('no complete backup yet', $monitor->run()['site:shop:backup'][2]);
    }
}
