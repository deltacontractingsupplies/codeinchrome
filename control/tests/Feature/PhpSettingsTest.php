<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhpSettingsTest extends TestCase
{
    private Site $site;

    private User $owner;

    private ?array $refusal = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake(['127.0.0.1:944*/v1/sites/*/php' => fn () => $this->refusal
            ? Http::response($this->refusal, 400)
            : Http::response(['ok' => true, 'applied' => ['changed' => 'yes']])]);
        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    public function test_the_owner_applies_php_settings_and_they_are_shown_after(): void
    {
        $this->actingAs($this->owner)->from(route('sites.settings', $this->site))
            ->put(route('sites.php', $this->site), ['memory_mb' => 200, 'upload_mb' => 48, 'max_execution_seconds' => ''])
            ->assertRedirect(route('sites.settings', $this->site))->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['memoryMB'] === 200 && $r['uploadMB'] === 48 && $r['maxExecutionSeconds'] === 0);
        $this->assertSame(['memoryMB' => 200, 'uploadMB' => 48], $this->site->fresh()->php_settings);
        $this->assertDatabaseHas('audit_events', ['action' => 'site.php']);
        $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertSee('value="200"', false)->assertSee('value="48"', false);
    }

    public function test_the_agents_reason_is_shown_and_nothing_is_stored_when_it_refuses(): void
    {
        $this->refusal = ['ok' => false, 'error' => 'cannot_apply', 'hint' => 'memory limit must be 64 to 448 MB on this plan'];
        $this->actingAs($this->owner)->from(route('sites.settings', $this->site))
            ->put(route('sites.php', $this->site), ['memory_mb' => 600])
            ->assertSessionHas('error', fn ($e) => str_contains($e, '64 to 448 MB'));
        $this->assertNull($this->site->fresh()->php_settings);
    }

    public function test_out_of_range_input_and_strangers_never_reach_the_host(): void
    {
        $this->actingAs($this->owner)->put(route('sites.php', $this->site), ['upload_mb' => 500])->assertSessionHasErrors('upload_mb');
        $this->actingAs(User::factory()->create())->put(route('sites.php', $this->site), ['memory_mb' => 128])->assertNotFound();
        Http::assertNothingSent();
    }
}
