<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The editor's file manager through the control plane: this layer decides
 * whose site it is, the agent decides what a path may be.
 */
class FileManagerApiTest extends TestCase
{
    private User $owner;

    private Site $site;

    private string $png = "\x89PNG\r\n\x1a\n\x00\xff\x00\x10";

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 'token-h1'],
        ]);
        Http::fake([
            '127.0.0.1:944*/v1/sites/*/download*' => Http::response($this->png, 200, [
                'Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="logo.png"']),
            '127.0.0.1:944*/v1/sites/*/upload*' => fn ($r) => Http::response(['ok' => true, 'bytes' => strlen($r->body())]),
            '127.0.0.1:944*/v1/sites/*/tree*' => fn ($r) => str_contains($r->url(), 'confirm=1')
                ? Http::response(['ok' => true, 'deleted' => true])
                : Http::response(['ok' => false, 'error' => 'needs_confirm', 'hint' => 'Pass confirm=1.'], 409),
            '127.0.0.1:944*/v1/sites/*/grep*' => Http::response(['ok' => true, 'hits' => [['path' => '/routes/web.php', 'line' => 3, 'text' => "Route::get('/')"]], 'truncated' => false]),
            '127.0.0.1:944*/v1/sites/*/find*' => Http::response(['ok' => true, 'entries' => [['path' => '/app/Models', 'dir' => true, 'size' => 96, 'mtime' => 1]], 'files' => 12, 'bytes' => 4096, 'truncated' => false]),
            '127.0.0.1:944*/v1/sites/*/clone' => fn ($r) => str_contains($r['repository'], 'kit')
                ? Http::response(['ok' => false, 'error' => 'malware', 'hint' => 'shell.php: webshell.', 'findings' => [['path' => '/kit/shell.php', 'rule' => 'webshell']]], 400)
                : Http::response(['ok' => true, 'clone' => ['repository' => 'acme/demo', 'ref' => 'HEAD', 'into' => '/demo', 'files' => 3, 'bytes' => 99, 'ms' => 800]]),
            '127.0.0.1:944*/v1/sites/*/search*' => Http::response(['ok' => true, 'hits' => [['path' => '/routes/web.php', 'line' => 2, 'text' => 'checkout']]]),
            '127.0.0.1:944*/v1/sites/*/files/*' => Http::response(['ok' => true, 'done' => 'x']),
        ]);
        $this->owner = User::factory()->create();
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
    }

    private function url(string $route, array $q = []): string
    {
        return route($route, ['site' => $this->site] + $q);
    }

    public function test_the_owner_can_make_move_copy_zip_unzip_and_search(): void
    {
        $as = $this->actingAs($this->owner);
        $as->postJson($this->url('files.mkdir'), ['path' => '/app/Shop'])->assertOk();
        $as->postJson($this->url('files.move'), ['from' => '/a.php', 'to' => '/b.php'])->assertOk();
        $as->postJson($this->url('files.copy'), ['from' => '/a.php', 'to' => '/c.php'])->assertOk();
        $as->postJson($this->url('files.zip'), ['from' => '/theme', 'to' => '/theme.zip'])->assertOk();
        $as->postJson($this->url('files.unzip'), ['archive' => '/theme.zip', 'into' => '/x'])->assertOk();
        $as->getJson($this->url('files.search', ['q' => 'checkout']))->assertOk()->assertJsonPath('hits.0.line', 2);

        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/v1/sites/shop/files/move')
            && $r['from'] === '/a.php' && $r['to'] === '/b.php');
    }

    public function test_a_folder_delete_needs_confirm_all_the_way_down(): void
    {
        $this->actingAs($this->owner)->deleteJson($this->url('files.tree.destroy', ['path' => '/app/Old']))
            ->assertStatus(409)->assertJsonPath('error', 'needs_confirm');
        $this->actingAs($this->owner)->deleteJson($this->url('files.tree.destroy', ['path' => '/app/Old', 'confirm' => 1]))
            ->assertOk();
    }

    public function test_an_upload_reaches_the_host_byte_for_byte(): void
    {
        $file = UploadedFile::fake()->createWithContent('logo.png', $this->png);
        $this->actingAs($this->owner)->post($this->url('files.upload'), ['path' => '/public/logo.png', 'file' => $file], ['Accept' => 'application/json'])
            ->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/upload?path=%2Fpublic%2Flogo.png') && $r->body() === $this->png);
    }

    public function test_a_download_is_an_inert_attachment_with_the_exact_bytes(): void
    {
        $res = $this->actingAs($this->owner)->get($this->url('files.download', ['path' => '/public/logo.png']));
        $res->assertOk();
        $this->assertSame($this->png, $res->streamedContent());
        $this->assertSame('attachment; filename="logo.png"', $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('sandbox', $res->headers->get('Content-Security-Policy'));
    }

    public function test_every_file_manager_route_refuses_someone_elses_site(): void
    {
        $as = $this->actingAs(User::factory()->create());
        $as->postJson($this->url('files.mkdir'), ['path' => '/x'])->assertNotFound();
        $as->postJson($this->url('files.move'), ['from' => '/a', 'to' => '/b'])->assertNotFound();
        $as->postJson($this->url('files.copy'), ['from' => '/a', 'to' => '/b'])->assertNotFound();
        $as->postJson($this->url('files.zip'), ['from' => '/a', 'to' => '/a.zip'])->assertNotFound();
        $as->postJson($this->url('files.unzip'), ['archive' => '/a.zip', 'into' => '/b'])->assertNotFound();
        $as->deleteJson($this->url('files.tree.destroy', ['path' => '/a', 'confirm' => 1]))->assertNotFound();
        $as->getJson($this->url('files.search', ['q' => 'xx']))->assertNotFound();
        $as->getJson($this->url('files.grep', ['pattern' => 'xx']))->assertNotFound();
        $as->getJson($this->url('files.find'))->assertNotFound();
        $as->get($this->url('files.download', ['path' => '/a']))->assertNotFound();
        $as->post($this->url('files.upload'), ['path' => '/a', 'file' => UploadedFile::fake()->create('a.txt', 1)], ['Accept' => 'application/json'])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_search_has_its_own_limit_not_the_command_one(): void
    {
        // cic.check's review searches several times while artisan runs; on
        // the 20-a-minute command limit it failed a busy session.
        $route = app('router')->getRoutes()->getByName('files.search');
        $this->assertContains('throttle:search', $route->gatherMiddleware());
        $this->assertNotContains('throttle:command', $route->gatherMiddleware());
        $limit = \Illuminate\Support\Facades\RateLimiter::limiter('search')(request());
        $this->assertGreaterThanOrEqual(60, (is_array($limit) ? $limit[0] : $limit)->maxAttempts);
    }

    public function test_grep_and_find_carry_the_shells_flags_to_the_host(): void
    {
        $as = $this->actingAs($this->owner);
        $as->getJson($this->url('files.grep', ['pattern' => 'Route::(get|post)', 'regex' => 1, 'icase' => 0,
            'under' => '/routes', 'include' => ['*.php', '*.blade.php']]))
            ->assertOk()->assertJsonPath('hits.0.line', 3)->assertJsonPath('truncated', false);
        // Repeated include keys (Go reads a list that way), booleans as 1, and
        // a false flag left out rather than sent as "0".
        Http::assertSent(function ($r) {
            $q = parse_url($r->url(), PHP_URL_QUERY) ?? '';

            return str_contains($r->url(), '/v1/sites/shop/grep?')
                && str_contains($q, 'include=%2A.php&include=%2A.blade.php') && str_contains($q, 'regex=1')
                && str_contains($q, 'pattern=Route%3A%3A%28get%7Cpost%29') && ! str_contains($q, 'icase');
        });

        $as->getJson($this->url('files.find', ['name' => '*.php', 'type' => 'd', 'maxdepth' => 2, 'newer' => 1700000000]))
            ->assertOk()->assertJsonPath('entries.0.path', '/app/Models')->assertJsonPath('bytes', 4096);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/sites/shop/find?')
            && str_contains($r->url(), 'type=d') && str_contains($r->url(), 'maxdepth=2') && str_contains($r->url(), 'newer=1700000000'));
    }

    public function test_grep_and_find_refuse_flags_the_host_would_not_understand(): void
    {
        $as = $this->actingAs($this->owner);
        $as->getJson($this->url('files.grep'))->assertStatus(422);
        $as->getJson($this->url('files.grep', ['pattern' => str_repeat('a', 201)]))->assertStatus(422);
        $as->getJson($this->url('files.grep', ['pattern' => 'x', 'include' => array_fill(0, 11, '*.php')]))->assertStatus(422);
        $as->getJson($this->url('files.find', ['type' => 'l']))->assertStatus(422);
        $as->getJson($this->url('files.find', ['newer' => 'yesterday']))->assertStatus(422);
        Http::assertNothingSent();
        foreach (['files.grep', 'files.find'] as $name) {
            $this->assertContains('throttle:search', app('router')->getRoutes()->getByName($name)->gatherMiddleware());
        }
    }

    public function test_git_clone_reaches_the_host_only_for_a_github_repository(): void
    {
        $as = $this->actingAs($this->owner);
        $as->postJson($this->url('files.clone'), ['repository' => 'https://github.com/acme/demo.git', 'ref' => 'v1.2', 'into' => '/demo'])
            ->assertOk()->assertJsonPath('clone.files', 3);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/sites/shop/clone')
            && $r['repository'] === 'https://github.com/acme/demo.git' && $r['ref'] === 'v1.2' && $r['into'] === '/demo');

        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (['https://gitlab.com/a/b', 'file:///etc/passwd', 'https://github.com/a', 'https://github.com@evil.example.com/a/b'] as $bad) {
            $as->postJson($this->url('files.clone'), ['repository' => $bad])->assertStatus(422);
        }
        $as->postJson($this->url('files.clone'), ['repository' => 'acme/demo', 'ref' => '../x'])->assertStatus(422);
    }

    public function test_git_clone_is_limited_to_three_a_minute(): void
    {
        $as = $this->actingAs($this->owner);
        foreach (range(1, 3) as $n) {
            $as->postJson($this->url('files.clone'), ['repository' => 'acme/demo'])->assertOk();
        }
        $as->postJson($this->url('files.clone'), ['repository' => 'acme/demo'])->assertStatus(429);
    }

    public function test_cloning_malware_is_refused_like_any_upload(): void
    {
        $this->actingAs($this->owner)->postJson($this->url('files.clone'), ['repository' => 'acme/kit'])
            ->assertStatus(403)->assertJsonPath('error', 'malware');
    }

    public function test_git_clone_refuses_someone_elses_site(): void
    {
        $this->actingAs(User::factory()->create())->postJson($this->url('files.clone'), ['repository' => 'acme/demo'])->assertNotFound();
        Http::assertNothingSent();
    }
}
