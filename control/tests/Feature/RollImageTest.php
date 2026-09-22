<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RollImageTest extends TestCase
{
    private array $recreated = [];

    private array $brokenAfter = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.roll_check_seconds' => 2, 'fleet.roll_check_interval' => 1, 'fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't']]);
        $user = User::factory()->create();
        foreach (['aa-site', 'bb-site', 'cc-site'] as $id) {
            Site::create(['user_id' => $user->id, 'site_id' => $id, 'domain' => "$id.codeinchrome.com", 'host' => 'h1',
                'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);
        }
        Http::fake([
            '127.0.0.1:944*/v1/images' => Http::response(['ok' => true, 'sites' => [
                ['id' => 'aa-site', 'current' => false], ['id' => 'bb-site', 'current' => false],
                ['id' => 'cc-site', 'current' => false], ['id' => 'up-to-date', 'current' => true],
            ]]),
            '127.0.0.1:944*/v1/sites/*/recreate' => function ($r) {
                $this->recreated[] = explode('/', parse_url($r->url(), PHP_URL_PATH))[3];

                return Http::response(['ok' => true, 'outcome' => 'recreated']);
            },
            '*.codeinchrome.com*' => fn ($r) => Http::response('', in_array(parse_url($r->url(), PHP_URL_HOST), $this->brokenAfter, true) ? 502 : 200),
        ]);
    }

    public function test_every_outdated_site_is_moved_and_only_those(): void
    {
        $this->artisan('fleet:roll-image')->assertSuccessful();
        $this->assertSame(['aa-site', 'bb-site', 'cc-site'], $this->recreated);
    }

    public function test_the_first_broken_site_stops_the_roll(): void
    {
        $this->brokenAfter = ['bb-site.codeinchrome.com'];

        $this->artisan('fleet:roll-image')->assertFailed();

        $this->assertSame(['aa-site', 'bb-site'], $this->recreated, 'The roll carried on after a site broke.');
    }
}
