<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FleetSyncBackupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1'],
        ]);
        Site::create(['user_id' => User::factory()->create()->id, 'site_id' => 'kept', 'domain' => 'kept.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20010]);
    }

    public function test_the_newest_COMPLETE_backup_is_recorded(): void
    {
        Http::fake(['127.0.0.1:9441/v1/sites/kept/backups' => Http::response(['ok' => true, 'operation' => null, 'backups' => [
            ['id' => 'a1', 'time' => '2026-09-22T02:01:00Z', 'hasDatabase' => true],
            ['id' => 'b2', 'time' => '2026-09-23T02:01:00Z', 'hasDatabase' => true],
            // Files only: its database snapshot never landed, so it cannot restore the site.
            ['id' => 'c3', 'time' => '2026-09-24T02:01:00Z', 'hasDatabase' => false],
        ]])]);

        $this->artisan('fleet:sync-backups')->assertSuccessful();

        $this->assertSame('2026-09-23 02:01:00', Site::where('site_id', 'kept')->value('last_backup_at')?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_an_unreachable_host_keeps_the_last_known_time(): void
    {
        Site::where('site_id', 'kept')->first()->forceFill(['last_backup_at' => '2026-09-23 02:01:00'])->save();
        Http::fake(['127.0.0.1:9441/*' => fn () => throw new ConnectionException('connection refused')]);

        $this->artisan('fleet:sync-backups')->assertFailed();

        $this->assertSame('2026-09-23 02:01:00', Site::where('site_id', 'kept')->value('last_backup_at')?->format('Y-m-d H:i:s'));
    }

    public function test_a_site_with_no_backup_is_recorded_as_none(): void
    {
        Site::where('site_id', 'kept')->first()->forceFill(['last_backup_at' => '2026-09-23 02:01:00'])->save();
        Http::fake(['127.0.0.1:9441/v1/sites/kept/backups' => Http::response(['ok' => true, 'operation' => null, 'backups' => []])]);

        $this->artisan('fleet:sync-backups')->assertSuccessful();

        $this->assertNull(Site::where('site_id', 'kept')->value('last_backup_at'));
    }
}
