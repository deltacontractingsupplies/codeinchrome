<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A site that scans is paused at once; an ordinary one is left alone. */
class AbuseEgressTest extends TestCase
{
    public function test_a_scanning_site_is_paused_and_the_owner_told_an_ordinary_one_left_alone(): void
    {
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't', 'h3' => 't', 'h4' => 't']]);
        $user = User::factory()->create(['email' => 'scanner@gmail.com']);
        foreach (['quiet' => 20500, 'noisy' => 20501, 'porty' => 20502] as $id => $port) {
            Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
                'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => $port]);
        }
        $sent = [];
        Event::listen(MessageSent::class, function ($e) use (&$sent) { $sent[] = $e->message->getSubject(); });
        $paused = [];
        Http::fake(['127.0.0.1:*' => function (ClientRequest $r) use (&$paused) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/v1/egress') {
                return Http::response(['ok' => true, 'sites' => str_contains($r->url(), ':9441') ? [
                    ['site' => 'quiet', 'connections' => 12, 'distinct_hosts' => 4, 'distinct_ports' => 2],
                    ['site' => 'noisy', 'connections' => 400, 'distinct_hosts' => 380, 'distinct_ports' => 1],
                    ['site' => 'porty', 'connections' => 300, 'distinct_hosts' => 1, 'distinct_ports' => 300],
                ] : []]);
            }
            if (str_ends_with($path, '/suspended')) {
                $paused[] = explode('/', $path)[3];
            }

            return Http::response(['ok' => true, 'applied' => []]);
        }]);

        $this->artisan('abuse:egress')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['noisy', 'porty'], $paused);
        $this->assertSame('live', Site::where('site_id', 'quiet')->value('status'));
        $this->assertSame('suspended', Site::where('site_id', 'noisy')->value('status'));
        $this->assertContains('[codeinchrome] Site paused for scanning: noisy.codeinchrome.com', $sent);
        $this->assertNull($user->fresh()->banned_at, 'paused, not banned: the owner decides');
    }

    public function test_a_site_hammering_refused_targets_is_reported_once_an_hour_and_not_paused(): void
    {
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't']]);
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]]]);
        $user = User::factory()->create(['email' => 'brute@gmail.com']);
        foreach (['brute' => 20510, 'calm' => 20511] as $id => $port) {
            Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
                'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => $port]);
        }
        $sent = [];
        Event::listen(MessageSent::class, function ($e) use (&$sent) { $sent[] = $e->message->getSubject(); });
        Http::fake(['127.0.0.1:9441/v1/egress' => Http::response(['ok' => true, 'sites' => [
            ['site' => 'brute', 'connections' => 1, 'distinct_hosts' => 1, 'distinct_ports' => 1, 'refusals' => 55],
            ['site' => 'calm', 'connections' => 3, 'distinct_hosts' => 2, 'distinct_ports' => 1, 'refusals' => 4],
        ]]), '*' => Http::response(['ok' => true])]);

        $this->artisan('abuse:egress')->assertSuccessful();
        $this->artisan('abuse:egress')->assertSuccessful();

        $this->assertSame(['[codeinchrome] Refused connections from brute.codeinchrome.com'], $sent, 'once, not every two minutes');
        $this->assertSame('live', Site::where('site_id', 'brute')->value('status'), 'reported, not paused');
    }

    public function test_a_free_site_pushing_a_gigabyte_in_ten_minutes_is_paused_and_a_paid_one_reported(): void
    {
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't'],
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]]]);
        foreach (['flood' => 'free', 'bigpaid' => 'starter', 'reset' => 'free'] as $id => $plan) {
            Site::create(['user_id' => User::factory()->create(['plan' => $plan])->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
                'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20520 + Site::count()]);
        }
        $counter = ['flood' => 0, 'bigpaid' => 0, 'reset' => 5_000_000_000];
        $paused = [];
        Http::fake(['127.0.0.1:9441/*' => function (ClientRequest $r) use (&$counter, &$paused) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/suspended')) {
                $paused[] = explode('/', $path)[3];
            }

            return Http::response(['ok' => true, 'applied' => [], 'sites' => collect($counter)->map(fn ($b, $site) => [
                'site' => $site, 'connections' => 1, 'distinct_hosts' => 1, 'distinct_ports' => 1, 'refusals' => 0, 'sent_bytes' => $b])->values()->all()]);
        }]);

        for ($m = 0; $m <= 10; $m += 2) {
            $this->travelTo(now()->startOfMinute()->addMinutes($m === 0 ? 0 : 2));
            $this->artisan('abuse:egress');
            $counter['flood'] += 250_000_000;   // 125 MB/min: 1.25 GB over ten minutes
            $counter['bigpaid'] += 250_000_000;
            $counter['reset'] = $m === 4 ? 10_000_000 : $counter['reset'] + 1_000_000; // its bridge was recreated
        }

        $this->assertSame(['flood'], $paused, 'only the free site is paused');
        $this->assertSame('live', Site::where('site_id', 'bigpaid')->value('status'), 'a paid site is reported, not paused');
        $this->assertSame('live', Site::where('site_id', 'reset')->value('status'), 'a counter that started again is not a flood');
    }
}
