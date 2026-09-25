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
}
