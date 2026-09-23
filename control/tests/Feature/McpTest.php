<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpTest extends TestCase
{
    private Site $site;

    private User $owner;

    private ?array $agent = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake(['127.0.0.1:944*/v1/sites/*/mcp' => fn () => $this->agent
            ? Http::response($this->agent, 409)
            : Http::response('{"ok":true,"result":{"content":[{"type":"text","text":"users, orders"}],"structuredContent":{}}}', 200, ['Content-Type' => 'application/json'])]);
        $this->owner = User::factory()->create();
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.25', 'memory_limit' => '256m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    private function mcp(string $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->call('POST', route('mcp.call', $this->site), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    public function test_a_tool_call_passes_through_with_objects_intact(): void
    {
        $this->mcp('{"method":"tools/call","params":{"name":"database-schema","arguments":{}}}')
            ->assertOk()->assertExactJson(['ok' => true, 'result' => ['content' => [['type' => 'text', 'text' => 'users, orders']], 'structuredContent' => []]]);
        Http::assertSent(fn ($r) => $r->body() === '{"method":"tools/call","params":{"name":"database-schema","arguments":{}}}');
    }

    public function test_only_tool_methods_are_passed(): void
    {
        foreach (['{"method":"initialize","params":{}}', '{"method":"resources/read","params":{}}', '{"method":"tools/call","params":[]}', 'nope'] as $body) {
            $this->mcp($body)->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_a_site_without_boost_gets_the_install_hint(): void
    {
        $this->agent = ['ok' => false, 'error' => 'boost_not_installed', 'hint' => 'Laravel Boost is not installed in this site; run ...'];
        $this->mcp('{"method":"tools/list","params":{}}')->assertStatus(409)->assertJsonPath('error', 'boost_not_installed');
    }

    public function test_a_stranger_gets_nothing(): void
    {
        $this->mcp('{"method":"tools/list","params":{}}', User::factory()->create())->assertNotFound();
        Http::assertNothingSent();
    }
}
