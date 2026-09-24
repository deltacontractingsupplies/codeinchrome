<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\Subscription;
use App\Models\User;
use App\Security\Totp;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    private function withHibp(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);
    }

    private function enable2fa(User $user): string
    {
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [bcrypt('recov-codes1')]])->save();

        return $secret;
    }

    public function test_with_2fa_on_the_password_alone_does_not_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => bcrypt('a-good-password-1')]);
        $secret = $this->enable2fa($user);

        $this->post('/login', ['email' => 'a@example.com', 'password' => 'a-good-password-1'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get('/sites')->assertRedirect(route('login'));

        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/two-factor-challenge', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $user = User::factory()->create(['email' => 'b@example.com', 'password' => bcrypt('a-good-password-1')]);
        $secret = $this->enable2fa($user);
        $code = Totp::code($secret, intdiv(time(), 30));

        $this->post('/login', ['email' => 'b@example.com', 'password' => 'a-good-password-1']);
        $this->post('/two-factor-challenge', ['code' => $code]);
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->post('/login', ['email' => 'b@example.com', 'password' => 'a-good-password-1']);
        $this->post('/two-factor-challenge', ['code' => $code])
            ->assertSessionHasErrors(['code' => 'That code has already been used. Wait for the next one in your app.']);
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $user = User::factory()->create(['email' => 'c@example.com', 'password' => bcrypt('a-good-password-1')]);
        $this->enable2fa($user);

        $this->post('/login', ['email' => 'c@example.com', 'password' => 'a-good-password-1']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recov-codes1']);
        $this->assertAuthenticated();

        $this->post('/logout');
        $this->post('/login', ['email' => 'c@example.com', 'password' => 'a-good-password-1']);
        $this->post('/two-factor-challenge', ['recovery_code' => 'recov-codes1'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_guessing_is_locked_out_per_account_with_a_readable_message(): void
    {
        $user = User::factory()->create(['email' => 'e@example.com', 'password' => bcrypt('a-good-password-1')]);
        $secret = $this->enable2fa($user);
        $this->post('/login', ['email' => 'e@example.com', 'password' => 'a-good-password-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }
        // Even the RIGHT code is refused once locked out.
        $this->post('/two-factor-challenge', ['code' => Totp::code($secret, intdiv(time(), 30))])
            ->assertSessionHasErrors('code');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('code'));
        $this->assertGuest();
    }

    public function test_the_half_signed_in_state_expires(): void
    {
        $user = User::factory()->create(['email' => 'd@example.com', 'password' => bcrypt('a-good-password-1')]);
        $secret = $this->enable2fa($user);
        $this->post('/login', ['email' => 'd@example.com', 'password' => 'a-good-password-1']);

        $this->travel(11)->minutes();
        $this->post('/two-factor-challenge', ['code' => Totp::code($secret, intdiv(time(), 30))])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_setup_only_enables_after_a_correct_code_and_stores_the_secret_encrypted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('two-factor.setup'))->assertOk();
        $secret = session('two_factor.pending');
        $this->assertNull($user->fresh()->two_factor_confirmed_at, 'Enabled before any code was entered.');

        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($user)->post(route('two-factor.confirm'), ['code' => Totp::code($secret, intdiv(time(), 30))])
            ->assertOk()->assertSee('will not be shown again');

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw, 'The secret is stored in plain text.');
        $this->assertSame($secret, $user->fresh()->two_factor_secret);
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());
    }

    public function test_changing_the_password_requires_the_current_one(): void
    {
        $this->withHibp();
        $user = User::factory()->create(['password' => bcrypt('the-old-password-1')]);

        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'wrong',
            'password' => 'a-brand-new-password-9', 'password_confirmation' => 'a-brand-new-password-9'])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('account.password'), ['current_password' => 'the-old-password-1',
            'password' => 'a-brand-new-password-9', 'password_confirmation' => 'a-brand-new-password-9'])->assertSessionHas('status');
        $this->assertTrue(\Hash::check('a-brand-new-password-9', $user->fresh()->password));
    }

    public function test_account_deletion_is_refused_while_a_subscription_is_active(): void
    {
        $user = User::factory()->create(['password' => bcrypt('the-password-1')]);
        Subscription::create(['user_id' => $user->id, 'ls_subscription_id' => 's', 'plan' => 'starter', 'status' => 'active']);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'the-password-1'])->assertSessionHas('error');
        $this->assertNotNull(User::find($user->id));
    }

    public function test_account_deletion_removes_every_site_and_keeps_the_account_if_one_survives(): void
    {
        config(['fleet.hosts' => ['h1' => ['ip' => '10.0.0.1', 'tunnel_port' => 9441, 'capacity' => 10]], 'fleet.tokens' => ['h1' => 't'],
            'fleet.cloudflare' => ['token' => 't', 'zone_id' => 'z', 'zone_name' => 'codeinchrome.com']]);
        $dataStays = true;
        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true, 'errors' => [], 'result' => []]),
            '127.0.0.1:944*/v1/sites/*' => function () use (&$dataStays) {
                return $dataStays
                    ? Http::response(['ok' => false, 'error' => 'partially_removed', 'parts' => ['container' => 'removed', 'data' => 'failed']], 500)
                    : Http::response(['ok' => true, 'parts' => ['container' => 'removed', 'data' => 'removed']]);
            },
        ]);
        $user = User::factory()->create(['password' => bcrypt('the-password-1')]);
        Site::create(['user_id' => $user->id, 'site_id' => 'one', 'domain' => 'one.codeinchrome.com', 'host' => 'h1',
            'status' => 'live', 'cpu_limit' => '0.5', 'memory_limit' => '512m']);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'the-password-1'])->assertSessionHas('error');
        $this->assertNotNull(User::find($user->id), 'Deleted the account while a site was still on a host.');

        $dataStays = false;
        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'the-password-1'])->assertRedirect(route('home'));
        $this->assertNull(User::find($user->id));
        $this->assertSame(0, Site::count());
        $this->assertGuest();
    }
}
