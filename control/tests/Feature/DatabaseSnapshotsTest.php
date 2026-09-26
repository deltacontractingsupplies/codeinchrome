<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A site's database snapshots: taken before every import, migration and seeder (agent), listed and put back here. */
class DatabaseSnapshotsTest extends TestCase
{
    private User $owner;

    private Site $site;

    private const SNAP = '20260926T101500Z-before-migrate.sql.gz';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/db/snapshots/*/restore' => Http::response(['ok' => true, 'restored' => self::SNAP]),
            '127.0.0.1:944*/v1/sites/*/db/snapshots' => Http::response(['ok' => true, 'snapshots' => [
                ['name' => self::SNAP, 'reason' => 'before-migrate', 'at' => '2026-09-26T10:15:00Z', 'bytes' => 2048],
            ]]),
        ]);
        $this->owner = User::factory()->create();
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);
    }

    public function test_the_owner_sees_the_snapshots_newest_first(): void
    {
        $this->actingAs($this->owner)->getJson(route('db.snapshots', $this->site))
            ->assertOk()->assertJsonPath('snapshots.0.name', self::SNAP)->assertJsonPath('snapshots.0.reason', 'before-migrate');
    }

    public function test_a_restore_needs_confirm_and_is_recorded(): void
    {
        $url = route('db.snapshots.restore', [$this->site, self::SNAP]);
        $this->actingAs($this->owner)->postJson($url)->assertStatus(422);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restore'));

        $this->actingAs($this->owner)->postJson($url, ['confirm' => true])->assertOk()->assertJsonPath('restored', self::SNAP);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/sites/shop/db/snapshots/'.self::SNAP.'/restore') && $r['confirm'] === true);
        $this->assertDatabaseHas('audit_events', ['action' => 'db.snapshot_restored', 'site' => 'shop']);
    }

    public function test_only_the_owner_and_only_a_snapshot_name(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('db.snapshots', $this->site))->assertNotFound();
        $this->actingAs(User::factory()->create())->postJson(route('db.snapshots.restore', [$this->site, self::SNAP]), ['confirm' => true])->assertNotFound();
        // Anything but a snapshot's name never reaches the agent.
        foreach (['..%2Fsite.json', 'site.json', '20260926T101500Z-x.sql'] as $bad) {
            $this->actingAs($this->owner)->postJson("/sites/shop/db/snapshots/$bad/restore", ['confirm' => true])->assertNotFound();
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/restore'));
    }
}
