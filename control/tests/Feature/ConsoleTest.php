<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    private ?array $agentBody = null;

    private int $agentStatus = 200;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/command' => fn () => Http::response($this->agentBody
                ?? ['ok' => true, 'result' => ['exitCode' => 0, 'output' => 'Nothing to migrate.', 'truncated' => false, 'timedOut' => false]], $this->agentStatus),
            '127.0.0.1:944*/v1/sites/*/logs*' => Http::response(['ok' => true, 'log' => ['source' => 'app', 'lines' => 'x', 'truncated' => false]]),
        ]);
        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
    }

    public function test_a_command_runs_and_its_output_comes_back(): void
    {
        $this->actingAs($this->owner)->postJson(route('console.run', $this->site), ['tool' => 'artisan', 'args' => ['migrate']])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('result.output', 'Nothing to migrate.');
    }

    public function test_a_failed_command_still_returns_its_output(): void
    {
        $this->agentBody = ['ok' => false, 'error' => 'command_failed', 'hint' => 'exited 1',
            'result' => ['exitCode' => 1, 'output' => 'SQLSTATE[42S01]: table exists', 'truncated' => false, 'timedOut' => false]];

        $this->actingAs($this->owner)->postJson(route('console.run', $this->site), ['tool' => 'artisan', 'args' => ['migrate']])
            ->assertOk()->assertJsonPath('ok', false)->assertJsonPath('result.output', 'SQLSTATE[42S01]: table exists');
    }

    public function test_a_destructive_command_needs_confirm_and_is_409(): void
    {
        $this->agentBody = ['ok' => false, 'error' => 'needs_confirm', 'hint' => 'Nothing was run.'];
        $this->agentStatus = 409;

        $this->actingAs($this->owner)->postJson(route('console.run', $this->site), ['tool' => 'artisan', 'args' => ['migrate:fresh']])
            ->assertStatus(409)->assertJsonPath('error', 'needs_confirm');
    }

    public function test_only_artisan_and_composer_and_only_the_owner(): void
    {
        $this->actingAs($this->owner)->postJson(route('console.run', $this->site), ['tool' => 'bash', 'args' => ['-c', 'id']])
            ->assertStatus(422);

        $this->flushSession();
        $this->actingAs(User::factory()->create())->postJson(route('console.run', $this->site), ['tool' => 'artisan', 'args' => ['about']])
            ->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson(route('console.logs', ['site' => $this->site, 'source' => 'app']))
            ->assertNotFound();

        Http::assertNothingSent();
    }
}
