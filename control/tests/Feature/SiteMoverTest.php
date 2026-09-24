<?php

namespace Tests\Feature;

use App\Fleet\SiteMover;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A move switches a site's name to the new host only once the copy there is
 * complete and answering; anything short of that leaves the source serving
 * and removes the copy. Two fake agents record every call, in order.
 */
class SiteMoverTest extends TestCase
{
    /** @var list<string> "host METHOD path" */
    private array $calls = [];

    private int $targetStatus = 200;

    private bool $truncatedExport = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => [
                'h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10, 'state' => 'draining'],
                'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10, 'state' => 'active'],
            ],
            'fleet.tokens' => ['h1' => 't1', 'h3' => 't3'],
            'fleet.zone' => 'codeinchrome.com',
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'],
            'fleet.stock.fresh_seconds' => 600,
        ]);
        \App\Fleet\Stock::remember('h3', ['cpus' => 8, 'memTotalBytes' => 16 << 30, 'diskTotalBytes' => 200 << 30]);
        $gz = gzencode('payload');
        Http::fake([
            'api.cloudflare.com/*' => function ($r) {
                $this->calls[] = 'dns '.$r->method().' '.($r['content'] ?? '');

                return Http::response(['success' => true, 'errors' => [], 'result' => $r->method() === 'GET' ? [] : ['id' => 'rec']]);
            },
            '127.0.0.1:944*' => function ($r) use ($gz) {
                $host = str_contains($r->url(), ':9441') ? 'h1' : 'h3';
                $path = parse_url($r->url(), PHP_URL_PATH);
                $this->calls[] = "$host {$r->method()} $path";
                $export = $this->truncatedExport ? substr($gz, 0, -4) : $gz;

                return match (true) {
                    $path === '/v1/host' => Http::response(['ok' => true, 'version' => config('fleet.min_agent_version')]),
                    $r->method() === 'GET' && $path === '/v1/sites/shop' && $host === 'h3' => Http::response(['ok' => false, 'error' => 'not_found'], 404),
                    $r->method() === 'POST' && $path === '/v1/sites' => Http::response(['ok' => true, 'site' => ['id' => 'shop', 'port' => 20555]], 201),
                    str_ends_with($path, '/db/export'), str_ends_with($path, '/transfer/files'), str_ends_with($path, '/transfer/history')
                        => $r->method() === 'GET' ? Http::response($export, 200, ['Content-Type' => 'application/gzip']) : Http::response(['ok' => true]),
                    str_ends_with($path, '/request') => Http::response(['ok' => true, 'response' => ['status' => $this->targetStatus, 'headers' => [], 'body' => '']]),
                    $r->method() === 'DELETE' => Http::response(['ok' => true, 'parts' => ['container' => 'removed']]),
                    default => Http::response(['ok' => true, 'applied' => []]),
                };
            },
        ]);
    }

    private function site(): Site
    {
        return Site::create(['user_id' => User::factory()->create()->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'disk_gb' => 10, 'port' => 20000,
            'queue' => true, 'php_settings' => ['memoryMB' => 200, 'maxExecutionSeconds' => 0, 'uploadMB' => 0]]);
    }

    public function test_a_move_copies_everything_then_switches_then_removes_the_source(): void
    {
        $site = $this->site();

        $this->assertSame(0, Artisan::call('fleet:drain', ['host' => 'h1']));

        $site->refresh();
        $this->assertSame(['h3', 20555], [$site->host, $site->port]);
        $order = array_values(array_filter($this->calls, fn ($c) => ! str_contains($c, '/v1/host')));
        $pos = fn (string $needle) => collect($order)->search(fn ($c) => str_contains($c, $needle));
        $this->assertLessThan($pos('h1 PUT /v1/sites/shop/maintenance'), $pos('h3 POST /v1/sites'));
        $this->assertLessThan($pos('h3 PUT /v1/sites/shop/db/import'), $pos('h1 GET /v1/sites/shop/db/export'));
        $this->assertLessThan($pos('h3 PUT /v1/sites/shop/transfer/files'), $pos('h1 GET /v1/sites/shop/transfer/files'));
        $this->assertNotFalse($pos('h3 PUT /v1/sites/shop/background'), 'the queue worker is switched on at the target');
        $this->assertNotFalse($pos('h3 PUT /v1/sites/shop/php'), 'the PHP settings follow the site');
        $this->assertLessThan($pos('dns POST 10.0.0.3'), $pos('h3 POST /v1/sites/shop/request'), 'checked before the switch');
        $this->assertLessThan($pos('h1 DELETE /v1/sites/shop'), $pos('dns POST 10.0.0.3'), 'the source goes only after the switch');
    }

    public function test_a_copy_that_does_not_answer_is_removed_and_the_source_keeps_serving(): void
    {
        $site = $this->site();
        $this->targetStatus = 500;

        $this->expectExceptionMessage('answered 500');
        try {
            SiteMover::make()->move($site);
        } finally {
            $site->refresh();
            $this->assertSame(['h1', 20000], [$site->host, $site->port]);
            $this->assertContains('h3 DELETE /v1/sites/shop', $this->calls);
            $this->assertNotContains('h1 DELETE /v1/sites/shop', $this->calls);
            $this->assertSame([], array_filter($this->calls, fn ($c) => str_starts_with($c, 'dns POST')), 'DNS untouched');
            $this->assertSame('h1 PUT /v1/sites/shop/maintenance', collect($this->calls)->last(fn ($c) => str_starts_with($c, 'h1 PUT')),
                'the source is taken out of maintenance');
        }
    }

    public function test_an_export_cut_off_part_way_is_never_imported(): void
    {
        $site = $this->site();
        $this->truncatedExport = true;

        try {
            SiteMover::make()->move($site);
            $this->fail('a truncated export was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('incomplete', $e->getMessage());
        }
        $this->assertNotContains('h3 PUT /v1/sites/shop/db/import', $this->calls);
        $this->assertSame('h1', $site->fresh()->host);
    }

    public function test_only_a_draining_host_is_drained_and_custom_domains_need_consent(): void
    {
        config(['fleet.hosts.h1.state' => 'active']);
        $this->assertSame(1, Artisan::call('fleet:drain', ['host' => 'h1']));

        $site = $this->site();
        $site->domains()->create(['domain' => 'shop.example.org', 'token' => 't', 'verified_at' => now()]);
        $this->expectExceptionMessage('custom domains');
        SiteMover::make()->move($site);
    }
}
