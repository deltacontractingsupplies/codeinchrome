<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The exposure check fails a site that gives anything private away, passes a
 * clean one, and never puts the site's secrets in its own answer.
 */
class ExposureCheckTest extends TestCase
{
    private const APP_KEY = 'base64:Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyMTI=';

    private const DB_PASSWORD = 'k3pt-s3cret-db-pw';

    /** path => [status, body] the live site gives; everything else is 404 */
    private array $leaks = [];

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Http::fake([
            '127.0.0.1:9441/*' => function ($r) {
                if (str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/paths')) {
                    return Http::response(['ok' => true, 'paths' => ['/public/index.php', '/public/old-backup.sql', '/public/.env.save', '/public/notes.txt', '/public/build/app.js', '/app/X.php'], 'truncated' => false]);
                }

                return Http::response(['ok' => true, 'content' => 'APP_KEY='.self::APP_KEY."\nDB_PASSWORD=".self::DB_PASSWORD."\nAPP_DEBUG=false\n", 'revision' => 'r']);
            },
            'shop.codeinchrome.com/*' => function ($r) {
                $path = substr($r->url(), strlen('https://shop.codeinchrome.com'));
                [$status, $body] = $this->leaks[$path] ?? [404, 'Not found'];

                return Http::response($body, $status);
            },
        ]);
        $this->owner = User::factory()->create(['plan' => 'starter']);
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'port' => 20000]);
    }

    public function test_a_clean_site_passes_every_path(): void
    {
        $r = $this->actingAs($this->owner)->getJson(route('sites.exposure', $this->site))->assertOk()->json();

        $this->assertTrue($r['passed']);
        $this->assertSame(0, $r['failed']);
        $paths = array_column($r['results'], 'path');
        foreach (['/.env', '/.git/config', '/%2e%2e/.env', '/storage/logs/laravel.log', '/old-backup.sql', '/.env.save', '/notes.txt', '/index.php'] as $p) {
            $this->assertContains($p, $paths, "$p must be asked for");
        }
    }

    public function test_any_leak_fails_and_says_why_without_repeating_the_secret(): void
    {
        $this->leaks = [
            '/.env' => [200, 'APP_KEY='.self::APP_KEY],                          // the file itself
            '/%2e%2e/.env' => [200, 'debug: '.self::DB_PASSWORD],               // a secret in any page
            '/old-backup.sql' => [200, "CREATE TABLE users (id int);\n"],       // a dump left in public/
            '/composer.json' => [404, 'Not found but '.substr(self::APP_KEY, 7)], // even on an error page
            '/notes.txt' => [200, 'db pw: '.self::DB_PASSWORD],                  // an innocent name in public/
        ];
        $json = $this->actingAs($this->owner)->getJson(route('sites.exposure', $this->site))->assertOk()->getContent();
        $r = json_decode($json, true);

        $this->assertTrue($r['ok'], 'the check ran');
        $this->assertFalse($r['passed'], 'and found the leaks');
        $failed = collect($r['results'])->where('ok', false)->pluck('path')->sort()->values()->all();
        $this->assertSame(['/%2e%2e/.env', '/.env', '/composer.json', '/notes.txt', '/old-backup.sql'], $failed);
        $this->assertStringNotContainsString(self::DB_PASSWORD, $json, 'the report never repeats a secret');
        $this->assertStringNotContainsString(substr(self::APP_KEY, 7), $json);
    }

    public function test_only_the_owner_can_run_it(): void
    {
        $this->actingAs(User::factory()->create())->getJson(route('sites.exposure', $this->site))->assertNotFound();
        $this->assertContains($this->getJson(route('sites.exposure', $this->site))->status(), [401, 404], 'a visitor is refused');
    }
}
