<?php

namespace Tests\Feature;

use App\Fleet\PlanLimits;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A queue worker, the scheduler and Reverb, switched per site - on paid plans.
 */
class BackgroundProcessesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't'],
        ]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/background' => Http::response(['ok' => true, 'applied' => ['changed' => 'yes', 'workers' => '14']]),
            '127.0.0.1:944*/v1/sites/*/limits' => Http::response(['ok' => true, 'applied' => []]),
        ]);
    }

    private function site(User $user): Site
    {
        return Site::create(['user_id' => $user->id, 'site_id' => 'jobs', 'domain' => 'jobs.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
    }

    public function test_a_paid_site_switches_its_processes_on(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        $site = $this->site($user);

        $this->actingAs($user)->put(route('sites.background', $site), ['queue' => '1', 'scheduler' => '1'])
            ->assertSessionHas('status', 'Applied. The site restarted with its new processes (14 web workers).');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/sites/jobs/background')
            && $r['queue'] === true && $r['scheduler'] === true && $r['reverb'] === false);
        $this->assertTrue($site->fresh()->queue);
        $this->assertTrue($site->fresh()->scheduler);
    }

    public function test_the_free_plan_cannot_and_the_host_is_never_asked(): void
    {
        $user = User::factory()->create(['plan' => 'free']);
        $site = $this->site($user);

        $this->actingAs($user)->put(route('sites.background', $site), ['queue' => '1'])
            ->assertSessionHas('error', 'Background processes come with the paid plans.');
        $this->actingAs($user)->get(route('sites.settings', $site))->assertOk()->assertSee('come with the paid plans');
        Http::assertNothingSent();
    }

    public function test_someone_elses_site_is_404(): void
    {
        $site = $this->site(User::factory()->create(['plan' => 'pro']));
        $stranger = User::factory()->create(['plan' => 'pro']);
        $this->actingAs($stranger)->get(route('sites.settings', $site))->assertNotFound();
        $this->actingAs($stranger)->put(route('sites.background', $site), ['queue' => '1'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_a_downgrade_to_free_switches_them_off(): void
    {
        $user = User::factory()->create(['plan' => 'starter']);
        $site = $this->site($user);
        $site->update(['queue' => true, 'reverb' => true]);

        $user->update(['plan' => 'free']);
        app(PlanLimits::class)->applyTo($user);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/background') && $r['queue'] === false && $r['reverb'] === false);
        $this->assertFalse($site->fresh()->queue);
        $this->assertFalse($site->fresh()->reverb);
    }
}
