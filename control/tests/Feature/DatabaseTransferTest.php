<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Database export and import from the editor. This layer decides whose site
 * it is and that an import was confirmed; the agent runs the dump as the
 * site's own MySQL user and saves the current database before an import.
 */
class DatabaseTransferTest extends TestCase
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
            '127.0.0.1:944*/v1/sites/*/db/export*' => fn ($r) => str_contains($r->url(), 'saved=before-import')
                ? Http::response("\x1f\x8bOLD", 200, ['Content-Type' => 'application/gzip', 'Content-Disposition' => 'attachment; filename="site_mine-before-import.sql.gz"'])
                : Http::response("\x1f\x8bDUMP", 200, ['Content-Type' => 'application/gzip', 'Content-Disposition' => 'attachment; filename="site_mine.sql.gz"']),
            '127.0.0.1:944*/v1/sites/*/db/import*' => Http::response(['ok' => true, 'imported' => true, 'saved' => 'before-import']),
        ]);

        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create([
            'user_id' => $this->owner->id, 'site_id' => 'mine', 'domain' => 'mine.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '1024m',
            'port' => 20000, 'provisioned_at' => now(),
        ]);
    }

    public function test_the_owner_downloads_the_database_as_an_attachment(): void
    {
        $r = $this->actingAs($this->owner)->get(route('db.export', $this->site))->assertOk();
        $this->assertSame("\x1f\x8bDUMP", $r->streamedContent());
        $this->assertStringContainsString('attachment; filename="site_mine.sql.gz"', $r->headers->get('Content-Disposition'));
        $this->assertSame('sandbox', $r->headers->get('Content-Security-Policy'));
        $this->assertDatabaseHas('audit_events', ['action' => 'db.exported']);
    }

    public function test_the_copy_saved_before_an_import_can_be_downloaded(): void
    {
        $r = $this->actingAs($this->owner)->get(route('db.export', $this->site).'?saved=before-import')->assertOk();
        $this->assertSame("\x1f\x8bOLD", $r->streamedContent());
    }

    public function test_an_import_needs_confirming_and_is_passed_through_and_audited(): void
    {
        $file = UploadedFile::fake()->createWithContent('backup.sql', "INSERT INTO t VALUES (1);\n");

        $this->actingAs($this->owner)->post(route('db.import', $this->site), ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        Http::assertNothingSent();

        $this->actingAs($this->owner)->post(route('db.import', $this->site), ['file' => $file, 'confirm' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', true)
            ->assertJsonPath('undo', route('db.export', $this->site).'?saved=before-import');

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/v1/sites/mine/db/import?confirm=1')
            && $r->body() === "INSERT INTO t VALUES (1);\n");
        $this->assertDatabaseHas('audit_events', ['action' => 'db.imported']);
    }

    public function test_a_file_over_the_limit_is_refused_before_the_host(): void
    {
        $big = UploadedFile::fake()->create('big.sql', 96 * 1024);

        $this->actingAs($this->owner)->post(route('db.import', $this->site), ['file' => $big, 'confirm' => '1'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        Http::assertNothingSent();
    }

    public function test_a_stranger_can_neither_export_nor_import(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('db.export', $this->site))->assertNotFound();
        $this->actingAs($stranger)->post(route('db.import', $this->site), [
            'file' => UploadedFile::fake()->createWithContent('x.sql', 'DROP TABLE users;'), 'confirm' => '1',
        ])->assertNotFound();
        Http::assertNothingSent();
    }
}
