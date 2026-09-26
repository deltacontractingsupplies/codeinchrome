<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A site linked to its owner's GitHub repository (agent github.go), from the settings page. */
class GitHubLinkTest extends TestCase
{
    private ?array $link = null;

    private bool $hostDown = false;

    private User $owner;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        Http::fake(['127.0.0.1:944*/v1/sites/*/github*' => function (ClientRequest $r) {
            if ($this->hostDown) {
                throw new ConnectionException('tunnel down');
            }

            return match (true) {
                $r->method() === 'GET' => Http::response(['ok' => true] + ($this->link ? ['linked' => true, 'github' => $this->link] : ['linked' => false])),
                $r->method() === 'DELETE' => Http::response(['ok' => true, 'linked' => false]),
                str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/push') => Http::response(['ok' => true, 'linked' => true, 'github' => ['state' => 'linked'] + $this->link]),
                default => Http::response(['ok' => true, 'linked' => true, 'github' => $this->link = [
                    'repo' => $r['repo'], 'branch' => $r['branch'], 'state' => 'waiting_for_key',
                    'publicKey' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAbCdEf codeinchrome site shop',
                    'hint' => 'Add this site\'s key...',
                ]]),
            };
        }]);
        $this->owner = User::factory()->create(['plan' => 'free']);
        $this->site = Site::create(['user_id' => $this->owner->id, 'site_id' => 'shop', 'domain' => 'shop.codeinchrome.com',
            'host' => 'h1', 'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '384m', 'port' => 20001]);
    }

    public function test_link_then_add_the_key_then_it_pushes(): void
    {
        $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertOk()
            ->assertSee('Link GitHub')->assertSee('On the free plan a deleted site is gone for good');

        $this->actingAs($this->owner)->post(route('sites.github.link', $this->site), ['repo' => 'acme/shop.git', 'branch' => 'main'])
            ->assertRedirect(route('sites.settings', $this->site).'#github')->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/sites/shop/github') && $r['repo'] === 'acme/shop' && $r['branch'] === 'main');
        $this->assertDatabaseHas('audit_events', ['action' => 'site.github_linked', 'site' => 'shop']);

        // Waiting for the key: the key, where to put it, and the one box to tick.
        $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertOk()
            ->assertSee('data-github-state="waiting_for_key"', false)
            ->assertSee('https://github.com/acme/shop/settings/keys/new')
            ->assertSee('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAbCdEf')->assertSee('Allow write access')->assertSee('Check again');

        $this->actingAs($this->owner)->post(route('sites.github.push', $this->site))->assertSessionHas('status', 'Pushed to GitHub.');

        $this->actingAs($this->owner)->delete(route('sites.github.unlink', $this->site))->assertSessionHas('status');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/sites/shop/github'));
        $this->assertDatabaseHas('audit_events', ['action' => 'site.github_unlinked', 'site' => 'shop']);
    }

    public function test_an_agent_links_it_through_json_and_gets_where_to_add_the_key(): void
    {
        $this->actingAs($this->owner)->getJson(route('sites.github', $this->site))->assertOk()->assertJsonPath('linked', false);
        $this->actingAs($this->owner)->postJson(route('sites.github.link', $this->site), ['repo' => 'acme/shop'])->assertOk()
            ->assertJsonPath('github.state', 'waiting_for_key')
            ->assertJsonPath('github.addKeyUrl', 'https://github.com/acme/shop/settings/keys/new')
            ->assertJsonPath('github.publicKey', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAbCdEf codeinchrome site shop');
        $this->actingAs($this->owner)->postJson(route('sites.github.push', $this->site))->assertOk()->assertJsonPath('github.state', 'linked');
        $this->actingAs(User::factory()->create())->getJson(route('sites.github', $this->site))->assertNotFound();
    }

    public function test_a_linked_site_shows_its_last_push_and_a_diverged_one_says_why(): void
    {
        $this->link = ['repo' => 'acme/shop', 'branch' => 'main', 'state' => 'linked', 'publicKey' => 'ssh-ed25519 AAAA x',
            'lastPushAt' => now()->subMinutes(3)->toIso8601String(), 'lastCommit' => 'abc1234def5678'];
        $page = $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertOk()
            ->assertSee('every version is pushed here')->assertSee('Last pushed 3 minutes ago, commit')->assertSee('<code>abc1234</code>', false)
            ->assertSee('Push now')->assertDontSee('Check again');
        $this->assertStringNotContainsString('@if', $page->getContent());
        $this->assertStringNotContainsString('@endif', $page->getContent());

        $this->link['state'] = 'diverged';
        $this->link['hint'] = 'GitHub\'s main has commits this site does not.';
        $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertOk()
            ->assertSee('GitHub has commits the site does not')->assertSee('GitHub&#039;s main has commits this site does not.', false);
    }

    public function test_only_the_owner_and_only_a_repository_name(): void
    {
        $this->actingAs(User::factory()->create())->post(route('sites.github.link', $this->site), ['repo' => 'acme/shop'])->assertNotFound();
        foreach (['acme', '../shop', 'acme/shop/x', 'acme/sh op', '-x/shop'] as $bad) {
            $this->actingAs($this->owner)->post(route('sites.github.link', $this->site), ['repo' => $bad])->assertSessionHasErrors('repo');
        }
        $this->actingAs($this->owner)->post(route('sites.github.link', $this->site), ['repo' => 'acme/shop', 'branch' => '..'])->assertSessionHasErrors('branch');
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_a_host_that_does_not_answer_is_said_not_shown_as_unlinked(): void
    {
        $this->hostDown = true;
        $this->actingAs($this->owner)->get(route('sites.settings', $this->site))->assertOk()
            ->assertSee('cannot be shown right now')->assertDontSee('Link GitHub');
    }
}
