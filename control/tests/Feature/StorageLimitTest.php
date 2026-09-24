<?php

namespace Tests\Feature;

use App\Fleet\Provisioner;
use App\Models\Site;
use App\Models\User;
use App\Notifications\PlanNotice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A plan's storage is the total of its sites' files and databases, measured
 * by fleet:sync-usage. Over it, bulk additions and new sites stop, the owner
 * is told once, and the sites themselves keep serving.
 */
class StorageLimitTest extends TestCase
{
    private int $used = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'billing.plans.starter.storage_gb' => 10,
        ]);
        Http::fake([
            '127.0.0.1:9441/v1/usage' => fn () => Http::response(['ok' => true, 'usage' => [
                ['id' => 'one', 'diskUsedBytes' => $this->used, 'diskSizeBytes' => 10 << 30, 'diskMounted' => true, 'databaseBytes' => 1 << 30],
                ['id' => 'two', 'diskUsedBytes' => 4 << 30, 'diskSizeBytes' => 10 << 30, 'diskMounted' => true, 'databaseBytes' => 0],
            ]]),
            '127.0.0.1:9441/*' => Http::response(['ok' => true, 'path' => '/x', 'size' => 1]),
        ]);
    }

    private function owner(): User
    {
        $user = User::factory()->create(['plan' => 'starter']);
        foreach (['one', 'two'] as $id) {
            Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
                'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'disk_gb' => 10, 'port' => 20000]);
        }

        return $user;
    }

    public function test_the_total_across_sites_and_databases_is_what_counts(): void
    {
        $user = $this->owner();

        // 4 GB + 4 GB files + 1 GB database = 9 GB: under 10, though no single site is near its ceiling.
        $this->used = 4 << 30;
        Artisan::call('fleet:sync-usage');
        $this->assertNull($user->fresh()->storage_over_at);

        // 6 + 4 + 1 = 11 GB: over, though each site is well under its own 10 GB.
        $this->used = 6 << 30;
        Artisan::call('fleet:sync-usage');
        Artisan::call('fleet:sync-usage');
        $this->assertNotNull($user->fresh()->storage_over_at);
        Notification::assertSentToTimes($user, PlanNotice::class, 1);
        Notification::assertSentTo($user, PlanNotice::class, fn ($n) => $n->kind === 'storage');

        // Back under: everything reopens, with no further email.
        $this->used = 1 << 30;
        Artisan::call('fleet:sync-usage');
        $this->assertNull($user->fresh()->storage_over_at);
        Notification::assertSentToTimes($user, PlanNotice::class, 1);
    }

    public function test_over_the_limit_bulk_additions_and_new_sites_are_refused_but_editing_is_not(): void
    {
        $user = $this->owner();
        $user->forceFill(['storage_over_at' => now()])->save();
        $site = Site::where('site_id', 'one')->first();

        $this->actingAs($user)->post(route('files.upload', $site), ['path' => '/big.bin', 'file' => UploadedFile::fake()->create('big.bin', 10)],
            ['Accept' => 'application/json'])->assertStatus(507)->assertJson(['error' => 'storage_full']);
        $this->actingAs($user)->postJson(route('files.unzip', $site), ['archive' => '/a.zip', 'into' => '/'])->assertStatus(507);
        $this->actingAs($user)->postJson(route('files.copy', $site), ['from' => '/a', 'to' => '/b'])->assertStatus(507);

        // Code edits still save: the way back under the limit stays open.
        $this->actingAs($user)->putJson(route('files.store', $site), ['path' => '/routes/web.php', 'content' => '<?php'])
            ->assertStatus(200);
        // Bulk does not, one file or a batch of them.
        $this->actingAs($user)->putJson(route('files.store', $site), ['path' => '/big.txt', 'content' => str_repeat('x', 70 * 1024)])
            ->assertStatus(507);
        $this->actingAs($user)->putJson(route('files.batch', $site), ['files' => array_map(
            fn ($i) => ['path' => "/f$i.txt", 'content' => str_repeat('x', 20 * 1024)], range(1, 4))])->assertStatus(507);

        $this->expectExceptionMessage('more than the plan');
        Provisioner::make()->provision($user->fresh(), 'three');
    }
}
