<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use App\Showcase\Explore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explore (owner, 2026-09-24): every free, live, built site, listed publicly
 * by its address and nothing else; told before a site is created.
 */
class ExploreTest extends TestCase
{
    private int $port = 21000;

    private function site(string $id, string $plan = 'free', string $status = 'live', ?string $email = null): Site
    {
        $user = User::factory()->create(['plan' => $plan, 'name' => "Owner of $id",
            'email' => $email ?? "owner-$id@example.com"]);

        $site = Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com",
            'host' => 'h1', 'status' => $status, 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => $this->port++]);
        // Passed the malware scan and the link check (LinkScannerTest covers the gate itself).
        // Past its first week: Explore lists nothing younger (Provisioner::NOINDEX_DAYS).
        $site->forceFill(['scanned_clean_at' => now(), 'links_clean_at' => now(), 'created_at' => now()->subDays(8)])->save();

        return $site;
    }

    private function built(): string
    {
        return '<!DOCTYPE html><html><head><title>Bakery</title></head><body><h1>Fresh bread</h1></body></html>';
    }

    public function test_only_free_live_built_sites_are_listed_and_only_by_their_address(): void
    {
        config(['showcase.demos' => ['ember-and-oak' => ['site' => 'shop', 'name' => 'Ember & Oak', 'what' => 'Coffee.', 'built' => 'Built by Claude.',
            'url' => 'https://shop.codeinchrome.com', 'admin_url' => null, 'admin_email' => null, 'admin_password' => null, 'hide' => [], 'feature' => []]]]);
        $this->site('bakery');                                          // listed
        $this->site('empty');                                           // Laravel's start page: not built
        $this->site('broken');                                          // answers 500
        $this->site('elsewhere');                                       // redirects away
        $this->site('down');                                            // unreachable
        $this->site('paying', plan: 'starter');                         // paid: never listed
        $this->site('paused', status: 'suspended');                     // not live
        $this->site('shop');                                            // a highlighted demo: shown there, not here
        $this->site('e2e-run', email: 'ed-abc@codeinchrome.test');     // the platform's own tests

        Http::fake([
            'https://bakery.codeinchrome.com/' => Http::response($this->built()),
            'https://empty.codeinchrome.com/' => Http::response("<html><body>Let's get started <a href=\"https://laravel.com/docs\">Docs</a></body></html>"),
            'https://broken.codeinchrome.com/' => Http::response('Server Error', 500),
            'https://elsewhere.codeinchrome.com/' => Http::response('', 302, ['Location' => 'https://example.com']),
            'https://down.codeinchrome.com/' => fn () => throw new ConnectionException('timed out'),
            // The demo's code window asks its host's agent: not the subject here.
            '127.0.0.1:*' => Http::response('', 503),
        ]);

        $this->artisan('explore:refresh')->expectsOutput('1 site(s) listed on Explore.')->assertSuccessful();
        $this->assertSame(['bakery.codeinchrome.com'], app(Explore::class)->listed());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'paying.') || str_contains($r->url(), 'e2e-run.') || str_contains($r->url(), 'shop.'));

        foreach (['/', '/explore'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringContainsString('href="https://bakery.codeinchrome.com" rel="nofollow ugc noopener noreferrer"', $html);
            // The address only: never the owner, their email, or the page's content.
            $this->assertStringNotContainsString('Owner of bakery', $html);
            $this->assertStringNotContainsString('owner-bakery@example.com', $html);
            $this->assertStringNotContainsString('Fresh bread', $html);
            foreach (['empty', 'broken', 'elsewhere', 'down', 'paying', 'paused', 'e2e-run'] as $not) {
                $this->assertStringNotContainsString("$not.codeinchrome.com", $html);
            }
        }
    }

    public function test_a_site_leaves_the_list_at_once_when_deleted_paused_or_upgraded(): void
    {
        $site = $this->site('bakery');
        Http::fake(['*' => Http::response($this->built())]);
        app(Explore::class)->refresh();
        $this->assertSame(['bakery.codeinchrome.com'], app(Explore::class)->listed());

        // No refresh in between: the list is filtered again on every read.
        $site->user->forceFill(['plan' => 'starter'])->save();
        $this->assertSame([], app(Explore::class)->listed());
        $site->user->forceFill(['plan' => 'free'])->save();
        $site->forceFill(['status' => 'suspended'])->save();
        $this->assertSame([], app(Explore::class)->listed());
        $site->forceFill(['status' => 'live'])->save();
        $site->delete();
        $this->assertSame([], app(Explore::class)->listed());
    }

    public function test_an_empty_list_says_so_and_never_fails(): void
    {
        $this->get('/explore')->assertOk()->assertSee('No free sites to show yet');
        $this->get('/')->assertOk()->assertSee('id="explore"', false);
    }

    public function test_the_person_is_told_before_creating_a_free_site_and_in_the_terms(): void
    {
        $free = User::factory()->create(['plan' => 'free', 'email_verified_at' => now()]);
        $this->actingAs($free)->get(route('dashboard'))->assertOk()
            ->assertSee('data-explore-notice', false)
            ->assertSee('Free sites are listed on');

        $paid = User::factory()->create(['plan' => 'starter', 'email_verified_at' => now()]);
        $this->actingAs($paid)->get(route('dashboard'))->assertOk()->assertDontSee('data-explore-notice', false);

        auth()->logout();
        $this->get('/terms')->assertOk()->assertSee('data-terms-explore', false)->assertSee('There is no opt-out on the free plan');
        $this->get('/privacy')->assertOk()->assertSee('data-privacy-explore', false);
        $this->get('/pricing')->assertOk()->assertSee('Listed on');
    }
}
