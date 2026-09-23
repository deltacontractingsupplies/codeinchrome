<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupTest extends TestCase
{
    private Site $site;

    private User $owner;

    private array $operation = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);

        Http::fake([
            '127.0.0.1:944*/v1/sites/*/backups/restore' => fn () => Http::response(['ok' => true, 'operation' => ['kind' => 'restore', 'state' => 'running']], 202),
            '127.0.0.1:944*/v1/sites/*/backups' => fn ($r) => $r->method() === 'POST'
                ? Http::response(['ok' => true, 'operation' => ['kind' => 'backup', 'state' => 'running']], 202)
                : Http::response(['ok' => true, 'operation' => $this->operation ?: null, 'backups' => [
                    ['id' => 'a1b2c3d4', 'time' => '2026-09-22T02:00:05Z', 'hasDatabase' => true],
                    ['id' => 'e5f6a7b8', 'time' => '2026-09-21T02:00:05Z', 'hasDatabase' => false],
                ]]),
        ]);

        $this->owner = User::factory()->create(['plan' => 'free']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '256m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    public function test_the_owner_sees_their_backups_and_only_complete_ones_offer_restore(): void
    {
        $page = $this->actingAs($this->owner)->get(route('sites.backups', $this->site))->assertOk();
        $page->assertSee('a1b2c3d4')->assertSee('22 Sep 2026, 02:00 UTC')->assertSee('e5f6a7b8')
            ->assertSee('Files only - cannot be restored as a whole');
        $this->assertSame(1, substr_count($page->getContent(), 'name="snapshot"'));
        $page->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_a_running_operation_is_shown_and_the_page_follows_it(): void
    {
        $this->operation = ['kind' => 'restore', 'state' => 'running', 'started' => now()->subMinute()->toIso8601String(),
            'message' => 'restoring files and database; the site is offline until this finishes'];
        $this->actingAs($this->owner)->get(route('sites.backups', $this->site))->assertOk()
            ->assertSee('Restoring')->assertSee('the site is offline until this finishes')
            ->assertSee('http-equiv="refresh"', false);
    }

    public function test_a_restore_needs_the_box_ticked_then_is_passed_through_and_audited(): void
    {
        $this->actingAs($this->owner)->from(route('sites.backups', $this->site))
            ->post(route('sites.backups.restore', $this->site), ['snapshot' => 'a1b2c3d4'])
            ->assertSessionHasErrors('confirm');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'restore'));

        $this->actingAs($this->owner)->from(route('sites.backups', $this->site))
            ->post(route('sites.backups.restore', $this->site), ['snapshot' => 'a1b2c3d4', 'confirm' => '1'])
            ->assertRedirect(route('sites.backups', $this->site))->assertSessionHas('status');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/sites/mine/backups/restore')
            && $r['snapshot'] === 'a1b2c3d4' && $r['confirm'] === true);
        $this->assertDatabaseHas('audit_events', ['action' => 'site.restore']);
    }

    public function test_a_malformed_snapshot_never_reaches_the_host(): void
    {
        $this->actingAs($this->owner)->post(route('sites.backups.restore', $this->site), ['snapshot' => '../../x', 'confirm' => '1'])
            ->assertSessionHasErrors('snapshot');
        Http::assertNothingSent();
    }

    public function test_back_up_now(): void
    {
        $this->actingAs($this->owner)->post(route('sites.backups.store', $this->site))->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/sites/mine/backups'));
    }

    public function test_a_stranger_sees_nothing_and_can_restore_nothing(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('sites.backups', $this->site))->assertNotFound();
        $this->actingAs($stranger)->post(route('sites.backups.restore', $this->site), ['snapshot' => 'a1b2c3d4', 'confirm' => '1'])->assertNotFound();
        $this->actingAs($stranger)->post(route('sites.backups.store', $this->site))->assertNotFound();
        Http::assertNothingSent();
    }
}
