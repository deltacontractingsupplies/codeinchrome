<?php

namespace Tests\Feature;

use App\Audit\Audit;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A paying customer's deleted site comes back from its final backup, on the host that holds it. */
class RestoreDeletedTest extends TestCase
{
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10], 'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1', 'h3' => 't3'],
            'fleet.zone' => 'codeinchrome.com',
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'],
            'fleet.poll_seconds' => 0,
        ]);
        Http::fake([
            'api.cloudflare.com/*' => fn ($r) => Http::response(['success' => true, 'errors' => [], 'result' => $r->method() === 'GET' ? [] : ['id' => 'rec']]),
            '127.0.0.1:944*' => function ($r) {
                $host = str_contains($r->url(), ':9441') ? 'h1' : 'h3';
                $path = parse_url($r->url(), PHP_URL_PATH);
                $this->calls[] = "$host {$r->method()} $path";

                return match (true) {
                    $path === '/v1/host' => Http::response(['ok' => true, 'version' => config('fleet.min_agent_version')]),
                    $path === '/v1/sites' => Http::response(['ok' => true, 'site' => ['id' => 'shop', 'port' => 20001]], 201),
                    str_ends_with($path, '/backups/restore') => Http::response(['ok' => true, 'operation' => ['kind' => 'restore', 'state' => 'running']]),
                    str_ends_with($path, '/backups') => Http::response(['ok' => true, 'backups' => [], 'operation' => ['kind' => 'restore', 'state' => 'done', 'snapshot' => 'a1b2c3d4']]),
                    default => Http::response(['ok' => true]),
                };
            },
        ]);
    }

    public function test_the_site_comes_back_on_the_host_holding_its_final_backup(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        Audit::record('site.final_backup', $user, 'shop', ['snapshot' => 'a1b2c3d4', 'host' => 'h3']);

        $this->assertSame(0, Artisan::call('site:restore-deleted', ['site' => 'shop']), Artisan::output());

        $this->assertSame('h3', Site::where('site_id', 'shop')->value('host'));
        $this->assertContains('h3 POST /v1/sites/shop/backups/restore', $this->calls);
        $this->assertDatabaseHas('audit_events', ['action' => 'site.restored_deleted', 'site' => 'shop']);
    }

    public function test_it_refuses_when_the_name_is_in_use_or_nothing_was_kept(): void
    {
        $this->assertSame(1, Artisan::call('site:restore-deleted', ['site' => 'ghost']));
        $this->assertStringContainsString('No final backup is recorded', Artisan::output());
    }
}
