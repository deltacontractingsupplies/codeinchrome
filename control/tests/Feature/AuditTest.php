<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuditTest extends TestCase
{
    public function test_actions_are_recorded_and_visible_only_to_their_account(): void
    {
        $user = User::factory()->create(['email' => 'aud@example.com', 'password' => bcrypt('a-password-123')]);
        $other = User::factory()->create();

        $this->post('/login', ['email' => 'aud@example.com', 'password' => 'wrong']);
        $this->post('/login', ['email' => 'aud@example.com', 'password' => 'a-password-123']);

        $this->assertSame(['auth.login_failed', 'auth.login'], AuditEvent::where('account_id', $user->id)->orderBy('id')->pluck('action')->all());

        $this->actingAs($user)->get(route('account.activity'))->assertOk()->assertSee('auth.login_failed');
        $this->flushSession();
        $this->actingAs($other)->get(route('account.activity'))->assertOk()->assertDontSee('auth.login_failed');
    }

    public function test_commands_and_database_writes_are_recorded_reads_are_not(): void
    {
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        $mode = 'read';
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/command' => Http::response(['ok' => true, 'result' => ['exitCode' => 0, 'output' => '']]),
            '127.0.0.1:944*/v1/sites/*/db/query' => function () use (&$mode) {
                return Http::response(['ok' => true, 'result' => ['mode' => $mode, 'columns' => [], 'rows' => [], 'rowsAffected' => 3]]);
            },
        ]);
        $user = User::factory()->create();
        $site = Site::create(['user_id' => $user->id, 'site_id' => 'aud', 'domain' => 'aud.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);

        $this->actingAs($user)->postJson(route('console.run', $site), ['tool' => 'artisan', 'args' => ['migrate']]);
        $this->actingAs($user)->postJson(route('db.query', $site), ['sql' => 'SELECT 1']);
        $mode = 'write';
        $this->actingAs($user)->postJson(route('db.query', $site), ['sql' => 'DELETE FROM x', 'write' => true]);

        $events = AuditEvent::where('site', 'aud')->orderBy('id')->get();
        $this->assertSame(['command.run', 'db.write'], $events->pluck('action')->all());
        $this->assertSame(['migrate'], $events[0]->detail['args']);
        $this->assertSame(3, $events[1]->detail['rows']);
    }

    public function test_history_cannot_be_rewritten_by_the_application(): void
    {
        $e = AuditEvent::create(['action' => 'x.test']);

        $this->expectException(\LogicException::class);
        $e->update(['action' => 'y.test']);
    }

    public function test_history_cannot_be_deleted_by_the_application(): void
    {
        $e = AuditEvent::create(['action' => 'x.test']);

        $this->expectException(\LogicException::class);
        $e->delete();
    }

    public function test_the_account_history_outlives_the_account(): void
    {
        $user = User::factory()->create(['password' => bcrypt('the-password-1')]);
        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'the-password-1']);

        $this->assertNull(User::find($user->id));
        $this->assertSame(1, AuditEvent::where('account_id', $user->id)->where('action', 'account.deleted')->count());
    }

    public function test_retention_removes_only_old_events(): void
    {
        AuditEvent::create(['action' => 'old.event', 'created_at' => now()->subDays(401)]);
        AuditEvent::create(['action' => 'recent.event', 'created_at' => now()->subDays(399)]);

        $this->artisan('audit:prune')->assertSuccessful();

        $this->assertSame(['recent.event'], AuditEvent::pluck('action')->all());
    }
}
