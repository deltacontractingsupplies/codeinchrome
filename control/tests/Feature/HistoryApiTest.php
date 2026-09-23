<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A site's history and bin, through the control plane. This layer decides
 * whose site it is; the agent decides what a path or version may be.
 */
class HistoryApiTest extends TestCase
{
    private const REV = 'a3f9c2d41b7e8f0a1c2d3e4f5a6b7c8d9e0f1a2b';

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/history/restore' => fn ($r) => Http::response(['ok' => true, 'path' => $r['path'], 'restoredFrom' => $r['rev']]),
            '127.0.0.1:944*/v1/sites/*/history*' => fn ($r) => str_contains($r->url(), 'rev=')
                ? Http::response(['ok' => true, 'content' => '<?php // old'])
                : Http::response(['ok' => true, 'versions' => [['commit' => self::REV, 'at' => '2026-09-23T10:00:00Z', 'message' => 'save routes/web.php']]]),
            '127.0.0.1:944*/v1/sites/*/bin*' => Http::response(['ok' => true, 'bin' => [['path' => 'old.php', 'deletedAt' => '2026-09-23T10:00:00Z', 'from' => self::REV]]]),
        ]);
        $this->owner = User::factory()->create();
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
    }

    public function test_the_owner_sees_versions_reads_one_and_sees_the_bin(): void
    {
        $this->actingAs($this->owner)->getJson("/sites/{$this->site->site_id}/history?path=/routes/web.php")
            ->assertOk()->assertJsonPath('versions.0.commit', self::REV);
        $this->actingAs($this->owner)->getJson("/sites/{$this->site->site_id}/history?path=/routes/web.php&rev=".self::REV)
            ->assertOk()->assertJsonPath('content', '<?php // old');
        $this->actingAs($this->owner)->getJson("/sites/{$this->site->site_id}/bin")
            ->assertOk()->assertJsonPath('bin.0.path', 'old.php');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/sites/shop/history') && str_contains($r->url(), 'path=%2Froutes%2Fweb.php'));
    }

    public function test_a_restore_reaches_the_agent_and_is_audited(): void
    {
        $this->actingAs($this->owner)->postJson("/sites/{$this->site->site_id}/history/restore", ['rev' => self::REV, 'path' => '/old.php'])
            ->assertOk()->assertJsonPath('restoredFrom', self::REV);

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['rev'] === self::REV && $r['path'] === '/old.php');
        $this->assertTrue(DB::table('audit_events')->where('action', 'file.restored')->exists());
    }

    public function test_someone_elses_site_is_refused_and_the_host_never_asked(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->getJson("/sites/{$this->site->site_id}/history")->assertNotFound();
        $this->actingAs($stranger)->getJson("/sites/{$this->site->site_id}/bin")->assertNotFound();
        $this->actingAs($stranger)->postJson("/sites/{$this->site->site_id}/history/restore", ['rev' => self::REV, 'path' => 'x'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_malformed_version_never_reaches_the_host(): void
    {
        foreach (['HEAD', 'main', '--output=/tmp/x', 'abc123'] as $rev) {
            $this->actingAs($this->owner)->postJson("/sites/{$this->site->site_id}/history/restore", ['rev' => $rev, 'path' => 'x'])
                ->assertUnprocessable();
        }
        Http::assertNothingSent();
    }
}
