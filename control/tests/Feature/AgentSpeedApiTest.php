<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The calls that make an agent fast - many files at once, an edit without
 * resending a file, a request to the live site - are authorised exactly like
 * every other file operation, and pass what the agent says through intact.
 */
class AgentSpeedApiTest extends TestCase
{
    private Site $site;

    private User $owner;

    /** @var list<array{string, string, array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake(['127.0.0.1:9441/*' => function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = [$r->method(), $path, json_decode($r->body(), true)];

            return match (true) {
                str_ends_with($path, '/files/batch') && ($r['files'][0]['path'] ?? '') === '/conflict.php' => Http::response(
                    ['ok' => false, 'error' => 'conflict', 'path' => '/conflict.php', 'hint' => 'NOTHING in this batch was written'], 409),
                str_ends_with($path, '/files/batch') => Http::response(['ok' => true, 'written' => array_map(
                    fn ($f) => ['path' => $f['path'], 'bytes' => strlen($f['content']), 'revision' => 'r'], $r['files'])]),
                str_ends_with($path, '/files/edit') => Http::response(['ok' => true, 'path' => $r['path'], 'bytes' => 3, 'revision' => 'r2']),
                str_ends_with($path, '/request') => Http::response(['ok' => true, 'response' => [
                    'status' => 200, 'headers' => ['content-type' => 'text/html'], 'body' => 'items: 3', 'truncated' => false, 'ms' => 4]]),
                default => Http::response(['ok' => false, 'error' => 'unexpected'], 500),
            };
        }]);
        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'port' => 20000]);
    }

    public function test_a_batch_goes_to_the_agent_as_one_call(): void
    {
        $this->actingAs($this->owner)->putJson(route('files.batch', $this->site), ['message' => 'inventory', 'files' => [
            ['path' => '/app/Models/Item.php', 'content' => '<?php'],
            ['path' => '/routes/web.php', 'content' => '', 'expect' => 'rev-1'],
        ]])->assertOk()->assertJsonCount(2, 'written');

        $this->assertCount(1, $this->sent);
        [$method, $path, $body] = $this->sent[0];
        $this->assertSame(['PUT', '/v1/sites/mine/files/batch'], [$method, $path]);
        $this->assertSame('inventory', $body['message']);
        $this->assertSame(['path' => '/routes/web.php', 'content' => '', 'expect' => 'rev-1'], $body['files'][1], 'An empty file is a file.');
    }

    public function test_a_batch_conflict_is_a_409_naming_the_file(): void
    {
        $this->actingAs($this->owner)->putJson(route('files.batch', $this->site), ['files' => [['path' => '/conflict.php', 'content' => 'x']]])
            ->assertStatus(409)->assertJson(['ok' => false, 'error' => 'conflict', 'path' => '/conflict.php']);
    }

    public function test_edits_and_requests_pass_through(): void
    {
        $this->actingAs($this->owner)->postJson(route('files.edit', $this->site), ['path' => '/config/app.php',
            'edits' => [['find' => "'Old'", 'replace' => "'New'"], ['find' => 'x', 'replace' => '', 'all' => true]]])
            ->assertOk()->assertJsonPath('revision', 'r2');
        $this->assertSame([['find' => "'Old'", 'replace' => "'New'", 'all' => false], ['find' => 'x', 'replace' => '', 'all' => true]], $this->sent[0][2]['edits']);

        $this->actingAs($this->owner)->postJson(route('sites.request', $this->site), ['method' => 'post', 'path' => '/items',
            'headers' => ['Cookie' => 'a=1'], 'body' => 'name=x'])
            ->assertOk()->assertJsonPath('response.body', 'items: 3');
        $this->assertSame(['method' => 'POST', 'path' => '/items', 'headers' => ['Cookie' => 'a=1'], 'body' => 'name=x'], $this->sent[1][2]);
    }

    public function test_a_request_must_be_a_path_on_the_site(): void
    {
        foreach (['http://169.254.169.254/latest', 'items', ''] as $bad) {
            $this->actingAs($this->owner)->postJson(route('sites.request', $this->site), ['path' => $bad])->assertStatus(422);
        }
        $this->actingAs($this->owner)->postJson(route('sites.request', $this->site), ['path' => '/', 'method' => 'CONNECT'])->assertStatus(422);
        $this->assertSame([], $this->sent);
    }

    public function test_nobody_else_can_use_them_and_a_paused_site_refuses_them(): void
    {
        $stranger = User::factory()->create(['plan' => 'starter']);
        $this->actingAs($stranger)->putJson(route('files.batch', $this->site), ['files' => [['path' => '/a', 'content' => 'x']]])->assertNotFound();
        $this->actingAs($stranger)->postJson(route('files.edit', $this->site), ['path' => '/a', 'edits' => [['find' => 'a', 'replace' => 'b']]])->assertNotFound();
        $this->actingAs($stranger)->postJson(route('sites.request', $this->site), ['path' => '/'])->assertNotFound();

        $this->site->update(['status' => 'suspended']);
        $this->actingAs($this->owner)->putJson(route('files.batch', $this->site), ['files' => [['path' => '/a', 'content' => 'x']]])->assertStatus(423);
        $this->actingAs($this->owner)->postJson(route('sites.request', $this->site), ['path' => '/'])->assertStatus(423);
        $this->assertSame([], $this->sent, 'Nothing reached a host.');
    }
}
