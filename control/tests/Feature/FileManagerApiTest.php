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
        $as->get($this->url('files.download', ['path' => '/a']))->assertNotFound();
        $as->post($this->url('files.upload'), ['path' => '/a', 'file' => UploadedFile::fake()->create('a.txt', 1)], ['Accept' => 'application/json'])->assertNotFound();
        Http::assertNothingSent();
    }
}
