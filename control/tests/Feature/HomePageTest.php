<?php

namespace Tests\Feature;

use App\Billing\Capacity;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The home page is the first thing a visitor sees, so it has to show what the
 * product is - the editor and the agent - and must never make a claim nothing
 * backs: capacity only from a measurement, the demo store only once it exists.
 */
class HomePageTest extends TestCase
{
    private function demoSite(callable|array $agent): void
    {
        Cache::flush();
        config([
            'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1'],
            'showcase.demos' => ['ember-and-oak' => ['site' => 'shop', 'name' => 'Ember & Oak', 'what' => 'Coffee.', 'built' => 'Built by Claude.',
                'url' => 'https://shop.codeinchrome.com', 'admin_url' => null, 'admin_email' => null, 'admin_password' => null,
                'hide' => [], 'feature' => ['/app/Http/Controllers/ShopController.php']]],
        ]);
        Site::firstOrCreate(['site_id' => 'shop'], ['user_id' => User::factory()->create()->id, 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);
        Http::fake(['127.0.0.1:9441/*' => is_callable($agent) ? $agent : function ($r) use ($agent) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/paths')
                ? Http::response(['ok' => true, 'paths' => array_keys($agent), 'truncated' => false])
                : Http::response(['ok' => true, 'content' => $agent[$q['path'] ?? ''] ?? '', 'revision' => 'r']);
        }]);
    }

    public function test_the_home_editor_shows_a_demos_live_code_with_the_editors_icons(): void
    {
        $this->demoSite([
            '/.env' => 'APP_KEY=base64:c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0MTI=',
            '/app/Http/Controllers/ShopController.php' => "<?php\nclass ShopController { /* live-marker-7f3 */ }",
            '/resources/views/shop/index.blade.php' => "@foreach (\$products as \$product)\n{{ \$products->links() }}\n<script>alert(1)</script>",
            '/routes/web.php' => '<?php',
        ]);

        $page = $this->get('/')->assertOk()
            ->assertSee('live-marker-7f3')                                  // the file as it is on the site now
            ->assertSee('/file-icons/php.svg', false)                       // the editor's own icons
            ->assertSee('/file-icons/folder-controller-open.svg', false)    // folders on the open file's path are open
            ->assertSee(route('demos.code', ['demo' => 'ember-and-oak', 'path' => '/resources/views/shop/index.blade.php']), false)
            ->assertDontSee('APP_KEY')->assertDontSee('.env</span>', false);
        // An icon with no light variant is not hidden in the light theme (it was, once: no icons at all).
        $this->assertStringContainsString('<img class="cw-ic " src="/file-icons/php.svg"', $page->getContent());

        // A second visit is served from the cache: the host is asked once per ten minutes, not per visitor.
        $sent = count(Http::recorded());
        $this->get('/')->assertOk()->assertSee('live-marker-7f3');
        $this->assertCount($sent, Http::recorded());
    }

    public function test_code_in_the_home_editor_is_printed_never_run(): void
    {
        $this->demoSite([
            '/app/Http/Controllers/ShopController.php' => "<?php\n// {{ \$secret }} @php(exit) <script>alert(1)</script>",
        ]);

        $this->get('/')->assertOk()
            ->assertSee('{{ $secret }} @php(exit)', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_home_page_never_fails_because_a_demo_host_cannot_be_asked(): void
    {
        // One fake with a switch: Http::fake stubs stack, and the first match wins.
        $down = true;
        $this->demoSite(function ($r) use (&$down) {
            if ($down) {
                throw new ConnectionException('connection refused');
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/paths')
                ? Http::response(['ok' => true, 'paths' => ['/app/Http/Controllers/ShopController.php'], 'truncated' => false])
                : Http::response(['ok' => true, 'content' => '<?php // last-good-4c1', 'revision' => 'r']);
        });
        $this->get('/')->assertOk()->assertDontSee('class="cw', false);

        // Once the demo has been read, a later outage shows the last good code instead.
        $down = false;
        $this->get('/')->assertOk()->assertSee('last-good-4c1');
        Cache::forget('demo-code:ember-and-oak:paths');
        Cache::forget('demo-code:ember-and-oak:file:'.sha1('/app/Http/Controllers/ShopController.php'));
        $down = true;
        $this->get('/')->assertOk()->assertSee('last-good-4c1');
    }

    public function test_the_editors_extensions_are_listed_with_their_projects_and_licences(): void
    {
        $extensions = json_decode(file_get_contents(resource_path('data/extensions.json')), true);
        $this->assertNotEmpty($extensions);

        $page = $this->get('/')->assertOk()->assertSee('Inside the editor');
        foreach ($extensions as $ext) {
            $page->assertSee($ext['name'])->assertSee($ext['licence']);
            if ($ext['url']) {
                $page->assertSee($ext['url'], false);
            }
        }
        $page->assertSee('https://github.com/mozilla/pdf.js', false);
    }

    public function test_getting_started_with_claude_in_chrome_is_step_by_step_and_says_who_is_paid(): void
    {
        $agent = config('agent');
        $page = $this->get('/')->assertOk();

        foreach (range(1, 5) as $step) {
            $page->assertSee("Step $step");
        }
        $page->assertSee('href="'.$agent['extension']['install'].'"', false)   // straight to the official listing
            ->assertSee('href="'.$agent['extension']['help'].'"', false)
            ->assertSee('href="'.$agent['plans_url'].'"', false)
            ->assertSee('not with the free one')
            ->assertSee('You buy it from Anthropic, not from us.')
            ->assertSee('It does not include an AI agent.')
            ->assertSee("{$agent['from']} to {$agent['to']} a month")
            ->assertSee('billed by Anthropic, separately from us')
            ->assertSee($agent['extension']['browser'])
            ->assertSee(route('register'), false);
        foreach ($agent['plans'] as $plan) {
            $page->assertSee("{$plan['name']} {$plan['price']}");
        }
        // Dated: Anthropic's prices are theirs to change.
        $page->assertSee(\Illuminate\Support\Carbon::parse($agent['checked_on'])->toFormattedDateString());
    }

    public function test_capacity_appears_only_for_measured_plans(): void
    {
        $file = tempnam(storage_path('framework/testing'), 'cap') ?: $this->fail('no temp file');
        try {
            file_put_contents($file, json_encode(['plans' => ['starter' => ['page_views_per_second' => 80, 'p95_ms' => 120]]]));
            $this->app->instance(Capacity::class, new Capacity($file));

            $this->get('/')->assertOk()->assertSee('How much traffic each plan handles')
                ->assertSee('~800')->assertSee('120 ms');

            $this->app->instance(Capacity::class, new Capacity($file . '.missing'));
            $this->get('/')->assertOk()->assertDontSee('How much traffic each plan handles');
        } finally {
            @unlink($file);
        }
    }

    public function test_the_demos_are_hidden_until_configured_then_show_login_and_code(): void
    {
        config(['showcase.demos' => []]);
        $this->get('/')->assertOk()->assertDontSee('Stores AI agents built here');

        config(['showcase.demos' => [
            'ember-and-oak' => ['site' => 'shop', 'name' => 'Ember & Oak', 'what' => 'Coffee.', 'built' => 'Built by Claude.',
                'url' => 'https://shop.codeinchrome.com', 'admin_url' => 'https://shop.codeinchrome.com/admin',
                'admin_email' => 'demo@shop.test', 'admin_password' => 'read-only-demo', 'hide' => []],
            'petal-and-stem' => ['site' => 'larashop', 'name' => 'Petal & Stem', 'what' => 'Flowers.', 'built' => 'Built by Claude in Chrome.',
                'url' => 'https://larashop.codeinchrome.com', 'admin_url' => null, 'admin_email' => null, 'admin_password' => null, 'hide' => []],
        ], 'showcase.recording' => '/showcase/build.gif']);
        $this->get('/')->assertOk()->assertSee('Stores AI agents built here')
            ->assertSee('Ember &amp; Oak', false)->assertSee('Petal &amp; Stem', false)
            ->assertSee('demo@shop.test')->assertSee('read-only-demo')->assertSee('/showcase/build.gif', false)
            ->assertSee(route('demos.code', 'ember-and-oak'), false)->assertSee(route('demos.code', 'petal-and-stem'), false);
    }


    public function test_the_home_page_says_the_code_is_public_and_on_what_terms(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="source"', false)
            ->assertSee('The code is public')
            ->assertSee('competing commercial product or service')
            ->assertSee('Apache 2.0 licence two years after its release')
            ->assertSee('https://github.com/deltacontractingsupplies/codeinchrome/blob/main/LICENSE.md', false)
            ->assertSee('https://github.com/deltacontractingsupplies/codeinchrome/security/policy', false);
    }

    public function test_getting_started_tells_the_person_to_hand_the_agent_the_editors_message(): void
    {
        $this->get('/')->assertOk()->assertSee('Copy for agent')->assertSee('Agent connected');
    }
}
