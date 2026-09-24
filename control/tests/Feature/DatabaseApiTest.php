<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DatabaseApiTest extends TestCase
{
    private Site $site;

    private User $owner;

    /** Configured by setting, never by a second Http::fake(): stubs merge. */
    private ?array $agentResponse = null;

    private int $agentStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);

        Http::fake([
            '127.0.0.1:944*/v1/sites/*/db*' => function ($r) {
                if ($this->agentResponse !== null) {
                    return Http::response($this->agentResponse, $this->agentStatus);
                }

                return str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/db')
                    ? Http::response(['ok' => true, 'database' => 'site_mine', 'tables' => [['name' => 'users']]])
                    : Http::response(['ok' => true, 'result' => ['columns' => ['n'], 'rows' => [['1']], 'mode' => 'read']]);
            },
        ]);

        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '1024m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    public function test_the_owner_can_list_tables_and_query(): void
    {
        $this->actingAs($this->owner)->getJson(route('db.tables', $this->site))
            ->assertOk()->assertJsonPath('tables.0.name', 'users');

        $this->actingAs($this->owner)->postJson(route('db.query', $this->site), ['sql' => 'SELECT 1 AS n'])
            ->assertOk()->assertJsonPath('result.rows.0.0', '1');

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['sql'] === 'SELECT 1 AS n' && $r['write'] === false);
    }

    public function test_write_is_passed_through_only_when_asked_for(): void
    {
        $this->actingAs($this->owner)->postJson(route('db.query', $this->site), ['sql' => 'DELETE FROM x', 'write' => true])
            ->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['write'] === true);
    }

    public function test_needs_write_is_409_and_distinct(): void
    {
        $this->agentResponse = ['ok' => false, 'error' => 'needs_write', 'hint' => 'Nothing was run.'];
        $this->agentStatus = 422;

        $this->actingAs($this->owner)->postJson(route('db.query', $this->site), ['sql' => 'DELETE FROM users'])
            ->assertStatus(409)->assertJsonPath('error', 'needs_write');
    }

    public function test_a_stranger_gets_404_and_nothing_reaches_the_host(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson(route('db.tables', $this->site))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('db.query', $this->site), ['sql' => 'SELECT 1'])->assertNotFound();
        // actingAs() persists for the rest of the test; sign out properly so
        // this request really is anonymous.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->postJson(route('db.query', $this->site), ['sql' => 'SELECT 1'])->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_sql_is_sent_byte_for_byte(): void
    {
        // TrimStrings would otherwise alter it; a trailing comment or
        // whitespace inside a string literal is part of the statement.
        $sql = "  SELECT '  padded  ' AS s -- note\n";

        $this->actingAs($this->owner)->postJson(route('db.query', $this->site), ['sql' => $sql])->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['sql'] === $sql);
    }
}
