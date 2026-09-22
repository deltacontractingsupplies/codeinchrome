<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * This controller's job is authorisation, so that is what these test.
 * Containment - that a path cannot escape a site - lives in the agent and is
 * tested there against a real filesystem, including symlinks.
 */
class FileApiTest extends TestCase
{
    private Site $site;

    private User $owner;

    /**
     * Configure the fake by setting these, NEVER by calling Http::fake() a
     * second time: stubs merge and the first match wins, so a later fake is
     * silently ignored and the test asserts against the earlier response.
     */
    private ?array $agentResponse = null;

    private int $agentStatus = 200;

    private bool $agentUnreachable = false;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com'],
        ]);

        Http::fake([
            '127.0.0.1:944*/v1/sites/*/files*' => function ($r) {
                if ($this->agentUnreachable) {
                    throw new \Illuminate\Http\Client\ConnectionException('tunnel down');
                }
                if ($this->agentResponse !== null) {
                    return Http::response($this->agentResponse, $this->agentStatus);
                }

                return match ($r->method()) {
                'PUT' => Http::response(['ok' => true, 'path' => $r['path'], 'bytes' => strlen($r['content'])]),
                'DELETE' => Http::response(['ok' => true, 'deleted' => true]),
                    default => str_contains($r->url(), 'read=1')
                        ? Http::response(['ok' => true, 'content' => '<?php // hello'])
                        : Http::response(['ok' => true, 'listing' => ['path' => '/', 'entries' => [], 'truncated' => false]]),
                };
            },
        ]);

        $this->owner = User::factory()->create(['plan' => 'pro']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '1024m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    public function test_the_owner_can_list_read_write_and_delete(): void
    {
        $this->actingAs($this->owner)
            ->getJson(route('files.index', $this->site))
            ->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($this->owner)
            ->getJson(route('files.index', ['site' => $this->site, 'read' => 1, 'path' => '/routes/web.php']))
            ->assertOk()->assertJsonPath('content', '<?php // hello');

        $this->actingAs($this->owner)
            ->putJson(route('files.store', $this->site), ['path' => '/app/X.php', 'content' => '<?php'])
            ->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($this->owner)
            ->deleteJson(route('files.destroy', ['site' => $this->site, 'path' => '/app/X.php']))
            ->assertOk()->assertJsonPath('deleted', true);
    }

    public function test_a_stranger_gets_404_not_403(): void
    {
        $stranger = User::factory()->create(['plan' => 'pro']);

        // 403 would confirm the site exists and belongs to someone else, which
        // lets anyone enumerate which names are taken.
        $this->actingAs($stranger)->getJson(route('files.index', $this->site))->assertNotFound();
        $this->actingAs($stranger)
            ->putJson(route('files.store', $this->site), ['path' => '/evil.php', 'content' => 'x'])
            ->assertNotFound();
        $this->actingAs($stranger)
            ->deleteJson(route('files.destroy', ['site' => $this->site, 'path' => '/routes/web.php']))
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_a_signed_out_visitor_gets_nowhere(): void
    {
        $this->getJson(route('files.index', $this->site))->assertUnauthorized();
        $this->putJson(route('files.store', $this->site), ['path' => '/x', 'content' => 'y'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_a_site_that_is_not_live_is_refused(): void
    {
        $this->site->update(['status' => 'provisioning']);

        $this->actingAs($this->owner)->getJson(route('files.index', $this->site))->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_emptying_a_file_is_allowed_but_a_missing_path_is_not(): void
    {
        // Present-but-empty content is a legitimate edit.
        $this->actingAs($this->owner)
            ->putJson(route('files.store', $this->site), ['path' => '/app/X.php', 'content' => ''])
            ->assertOk();

        $this->actingAs($this->owner)
            ->putJson(route('files.store', $this->site), ['content' => 'orphan'])
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->deleteJson(route('files.destroy', $this->site))
            ->assertStatus(422);
    }

    public function test_an_oversized_write_is_refused_before_it_reaches_the_host(): void
    {
        $this->actingAs($this->owner)
            ->putJson(route('files.store', $this->site), [
                'path' => '/big.txt',
                'content' => str_repeat('a', 2097153),
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_an_unreachable_host_is_503_and_says_the_state_is_unknown(): void
    {
        $this->agentUnreachable = true;

        $this->actingAs($this->owner)
            ->putJson(route('files.store', $this->site), ['path' => '/app/X.php', 'content' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'host_unreachable');
    }

    public function test_an_agent_refusal_reaches_the_browser_with_its_reason(): void
    {
        $this->agentResponse = ['ok' => false, 'error' => 'cannot_read', 'hint' => 'path is outside the site'];
        $this->agentStatus = 400;

        $this->actingAs($this->owner)
            ->getJson(route('files.index', ['site' => $this->site, 'read' => 1, 'path' => '/../../etc/passwd']))
            ->assertStatus(422)
            ->assertJsonPath('error', 'cannot_read')
            ->assertJsonPath('hint', 'path is outside the site');
    }
}
