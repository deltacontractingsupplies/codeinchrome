<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AccountsPurgeTestTest extends TestCase
{
    public function test_it_removes_only_test_accounts_that_own_nothing(): void
    {
        $test = User::factory()->create(['email' => 'e2e-1@codeinchrome.test']);
        $real = User::factory()->create(['email' => 'someone@codeinchrome.com']);
        $lookalike = User::factory()->create(['email' => 'x@codeinchrome.test.evil.com']);
        $busy = User::factory()->create(['email' => 'e2e-2@codeinchrome.test']);
        Site::create(['user_id' => $busy->id, 'site_id' => 'busy', 'domain' => 'busy.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);

        Artisan::call('accounts:purge-test');

        $this->assertNull(User::find($test->id));
        $this->assertNotNull(User::find($real->id), 'A real customer account was deleted.');
        $this->assertNotNull(User::find($lookalike->id), 'A look-alike domain was treated as a test account.');
        $this->assertNotNull(User::find($busy->id), 'An account that still owns a site was deleted.');
    }
}
