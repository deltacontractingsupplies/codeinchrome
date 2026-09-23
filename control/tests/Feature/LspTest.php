<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LspTest extends TestCase
{
    private Site $site;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/lsp/*' => fn ($r) => $r->method() === 'DELETE'
                ? Http::response(['ok' => true, 'closed' => true])
                : Http::response('{"ok":true,"messages":[{"jsonrpc":"2.0","id":1,"result":{"capabilities":{},"items":[]}}]}', 200, ['Content-Type' => 'application/json']),
        ]);
        $this->owner = User::factory()->create(['plan' => 'free']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.25', 'memory_limit' => '256m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    private function exchange(string $body, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->call('POST', route('lsp.exchange', $this->site), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    public function test_messages_pass_through_byte_faithfully_both_ways(): void
    {
        $r = $this->exchange('{"session":"tab0123456789abcdef","waitMs":3000,"messages":[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"capabilities":{},"rootUri":null}}]}');
        $r->assertOk();
        // {} stayed {} in both directions.
        $this->assertSame('{"ok":true,"messages":[{"jsonrpc":"2.0","id":1,"result":{"capabilities":{},"items":[]}}]}', $r->getContent());
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v1/sites/mine/lsp/tab0123456789abcdef')
            && $req->body() === '{"messages":[{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"capabilities":{},"rootUri":null}}],"waitMs":3000}');
    }

    public function test_bad_input_never_reaches_the_host(): void
    {
        foreach ([
            '{"session":"../../etc","messages":[]}',
            '{"session":"tab0123456789abcdef","messages":"x"}',
            '{"session":"tab0123456789abcdef","messages":[{"id":1,"method":"x"}]}',
            '{"session":"tab0123456789abcdef","messages":['.implode(',', array_fill(0, 201, '{"jsonrpc":"2.0","method":"x"}')).']}',
            'not json',
        ] as $body) {
            $this->exchange($body)->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_a_stranger_gets_nothing(): void
    {
        $this->exchange('{"session":"tab0123456789abcdef","messages":[]}', User::factory()->create())->assertNotFound();
        $this->actingAs(User::factory()->create())->delete(route('lsp.close', [$this->site, 'tab0123456789abcdef']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_owner_can_close_a_session(): void
    {
        $this->actingAs($this->owner)->delete(route('lsp.close', [$this->site, 'tab0123456789abcdef']))->assertOk();
        Http::assertSent(fn ($req) => $req->method() === 'DELETE' && str_ends_with($req->url(), '/lsp/tab0123456789abcdef'));
    }
}
