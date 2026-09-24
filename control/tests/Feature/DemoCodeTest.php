<?php

namespace Tests\Feature;

use App\Showcase\DemoSource;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The demos' source is public to READ. These tests are about everything else:
 * no other site, no settings file, no secret, no dependency, no way to write -
 * whether the path is listed or typed in by hand.
 */
class DemoCodeTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private const FILES = [
        '/.env' => "APP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTI=\nDB_PASSWORD=hunter2hunter2",
        '/.env.backup' => 'DB_PASSWORD=hunter2hunter2',
        '/production.env' => 'DB_PASSWORD=hunter2hunter2',
        '/app/.env' => 'DB_PASSWORD=hunter2hunter2',
        '/.git/config' => '[core]',
        '/public/.htaccess' => 'RewriteEngine On',
        '/app/Models/Flower.php' => "<?php\nclass Flower {}\n// <script>alert(1)</script>",
        '/routes/web.php' => "<?php\nRoute::get('/', fn () => 'hi');",
        '/README.md' => '# Petal & Stem',
        '/artisan' => "#!/usr/bin/env php\n<?php",
        '/vendor/laravel/framework/x.php' => '<?php',
        '/node_modules/a/index.js' => 'x',
        '/storage/logs/laravel.log' => 'secret log',
        '/storage/app/private/notes.txt' => 'private',
        '/bootstrap/cache/config.php' => "<?php return ['key' => 'x'];",
        '/database/database.sqlite' => 'SQLite format 3',
        '/backup.sql' => 'INSERT INTO users',
        '/flowershop-files.zip' => 'PK',
        '/_flowershop_staging/app/Old.php' => '<?php',
        '/composer.lock' => '{}',
        '/auth.json' => '{"github-oauth":{}}',
        // Assembled, so no secret-shaped literal is in this file for a scanner to flag.
        '/config/services.php' => "<?php return ['stripe' => ['secret' => '".'sk_'.'live_'.'51HqLyjWDarjtT1zdp7dcXYZabcdef'."']];",
        '/config/deploy.php' => '<?php // -----BEGIN RSA '.'PRIVATE KEY-----',
        '/database/seeders/DatabaseSeeder.php' => "<?php\nUser::create(['email' => 'a@b.test', 'password' => 'correct-horse-battery']);",
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'fleet.hosts' => ['h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10]],
            'fleet.tokens' => ['h3' => 't3'],
            'showcase.demos' => ['petal-and-stem' => [
                'site' => 'larashop', 'name' => 'Petal & Stem', 'what' => 'x', 'built' => 'Built by an agent.',
                'url' => 'https://larashop.codeinchrome.com', 'admin_url' => null, 'admin_email' => null, 'admin_password' => null,
                'hide' => ['_flowershop_staging', 'flowershop-files.zip'],
            ]],
        ]);
        Http::fake(['127.0.0.1:9443/*' => function ($r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $this->calls[] = $r->method().' '.$path.' '.($q['path'] ?? '');
            if (str_ends_with($path, '/paths')) {
                return Http::response(['ok' => true, 'paths' => array_keys(self::FILES), 'truncated' => false]);
            }

            return Http::response(['ok' => true, 'content' => self::FILES[$q['path'] ?? ''] ?? '', 'revision' => 'r']);
        }]);
        foreach (['larashop', 'secret-site'] as $id) {
            Site::create(['user_id' => User::factory()->create()->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
                'host' => 'h3', 'status' => 'live', 'cpu_limit' => '1.0', 'memory_limit' => '640m', 'port' => 20000]);
        }
    }

    public function test_only_readable_source_is_listed(): void
    {
        $html = $this->get('/demos/petal-and-stem/code')->assertOk()->getContent();
        // The file list, not the page's own words (which explain that .env is not shown).
        $this->assertSame(1, preg_match('#<nav[^>]*aria-label="Files"[^>]*>.*?</nav>#s', $html, $m));
        $page = $m[0];
        $this->assertStringNotContainsString('hunter2', $html);

        foreach (['Flower.php', 'web.php', 'README.md', 'artisan', 'services.php', 'DatabaseSeeder.php'] as $shown) {
            $this->assertStringContainsString($shown, $page, "$shown should be listed");
        }
        foreach (['.env', 'production.env', '.git', '.htaccess', 'vendor', 'node_modules', 'laravel.log', 'notes.txt', 'bootstrap',
            'database.sqlite', 'backup.sql', '.zip', '_flowershop_staging', 'Old.php', 'composer.lock', 'auth.json'] as $never) {
            $this->assertStringNotContainsString($never, $page, "$never must never be listed");
        }
        $this->assertStringNotContainsString('hunter2', $page);
    }

    public function test_a_path_typed_in_by_hand_is_held_to_the_same_rules(): void
    {
        foreach (['/.env', '/.env.backup', '/app/.env', '/production.env', '/storage/logs/laravel.log', '/vendor/laravel/framework/x.php',
            '/database/database.sqlite', '/backup.sql', '/_flowershop_staging/app/Old.php', '/app/../.env', '/../../etc/passwd',
            '/app/Models/Flower.php/../../../.env', '/nonexistent.php', 'routes/web.php'] as $path) {
            $this->get('/demos/petal-and-stem/code?path='.urlencode($path))->assertNotFound();
        }
        $read = array_filter($this->calls, fn ($c) => str_starts_with($c, 'GET /v1/sites/larashop/files'));
        $this->assertSame([], array_values(array_filter($read, fn ($c) => str_contains($c, '.env') || str_contains($c, 'sql') || str_contains($c, 'storage'))),
            'a refused path is never even read from the host');
    }

    public function test_only_the_configured_demos_are_served(): void
    {
        $this->get('/demos/secret-site/code')->assertNotFound();
        $this->get('/demos/larashop/code')->assertNotFound(); // the SITE id is not a demo key
        $this->get('/demos/'.urlencode('../petal-and-stem').'/code')->assertNotFound();
        $this->assertSame([], array_values(array_filter($this->calls, fn ($c) => str_contains($c, 'secret-site'))));
    }

    public function test_secrets_are_withheld_and_passwords_blanked(): void
    {
        $this->get('/demos/petal-and-stem/code?path=/config/services.php')->assertOk()
            ->assertSee('it may hold a secret')->assertDontSee('sk_live_');
        $this->get('/demos/petal-and-stem/code?path=/config/deploy.php')->assertOk()->assertDontSee('PRIVATE KEY');
        $this->get('/demos/petal-and-stem/code?path=/database/seeders/DatabaseSeeder.php')->assertOk()
            ->assertDontSee('correct-horse-battery')->assertSee('••••••');
    }

    public function test_code_is_shown_as_text_and_nothing_is_ever_written(): void
    {
        $this->get('/demos/petal-and-stem/code?path=/app/Models/Flower.php')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->$method('/demos/petal-and-stem/code')->assertStatus(405);
        }
        $this->assertSame([], array_values(array_filter($this->calls, fn ($c) => ! str_starts_with($c, 'GET '))), 'the viewer only ever reads');
    }

    public function test_the_allow_list_itself(): void
    {
        foreach (['/app/X.php', '/resources/views/a.blade.php', '/public/css/app.css', '/artisan', '/package.json'] as $ok) {
            $this->assertTrue(DemoSource::publishable($ok), $ok);
        }
        foreach (['/.env', '/.ENV', '/x/.env.local', '/a.env', '/.well-known/x.txt', '/public/storage/a.txt', '/image.png',
            '/app/x.phar', '/db.sqlite', '/x.pem', '/id_rsa', '/storage/framework/x.php', '/a/../b.php'] as $never) {
            $this->assertFalse(DemoSource::publishable($never), $never);
        }
    }

    public function test_an_unreachable_demo_host_is_a_friendly_503_never_a_500(): void
    {
        Cache::flush();
        Http::fake(['127.0.0.1:9443/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('connection refused')]);

        $this->get('/demos/petal-and-stem/code')
            ->assertStatus(503)
            ->assertSee('cannot be read just now')
            ->assertDontSee('ConnectionException');
    }
}
