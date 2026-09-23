<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;
use Mockery;
use Tests\TestCase;

/**
 * Sign in with Google or Apple.
 *
 * The rules that keep it from being a way into someone else's account:
 *   - an identity already linked signs in to that account, whatever its email
 *     says today;
 *   - a new identity joins an existing account only if the provider says it
 *     VERIFIED that email - otherwise anyone could claim an address;
 *   - two-factor still applies: the provider replaces the password, not the code.
 */
class SocialLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google' => ['client_id' => 'gid', 'client_secret' => 'gsecret', 'redirect' => 'https://app.test/auth/google/callback'],
            'services.apple' => ['client_id' => 'aid', 'client_secret' => '', 'redirect' => 'https://app.test/auth/apple/callback', 'private_key' => 'k', 'team_id' => 't', 'key_id' => 'k1'],
        ]);
    }

    private function providerReturns(string $provider, string $id, ?string $email, bool $verified, string $name = 'Pat Example'): void
    {
        $user = (new ProviderUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $id, 'email' => $email, 'name' => $name]);
        $driver = Mockery::mock(Provider::class);
        $driver->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    public function test_a_new_google_identity_creates_a_verified_account_and_signs_in(): void
    {
        $this->providerReturns('google', 'g-1', 'pat@example.com', verified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));

        $user = User::where('email', 'pat@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasVerifiedEmail(), 'The provider verified the address, so we need not ask again.');
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(SocialAccount::where(['provider' => 'google', 'provider_user_id' => 'g-1', 'user_id' => $user->id])->exists());
    }

    public function test_a_linked_identity_signs_in_to_its_account_even_if_its_email_changed(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2', 'email' => 'old@example.com']);
        $this->providerReturns('google', 'g-2', 'new@example.com', verified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count(), 'No second account for the same identity.');
    }

    public function test_a_verified_email_joins_the_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com']);
        $this->providerReturns('apple', 'a-1', 'pat@example.com', verified: true);

        $this->post('/auth/apple/callback')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(SocialAccount::where(['provider' => 'apple', 'user_id' => $user->id])->exists());
    }

    public function test_an_unverified_email_never_joins_an_existing_account(): void
    {
        User::factory()->create(['email' => 'victim@example.com']);
        $this->providerReturns('google', 'g-3', 'victim@example.com', verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email']);
        $this->assertGuest();
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_two_factor_still_applies(): void
    {
        $user = User::factory()->create(['email' => 'pat@example.com', 'two_factor_confirmed_at' => now(), 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);
        $this->providerReturns('google', 'g-4', 'pat@example.com', verified: true);

        $this->get('/auth/google/callback')->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->assertSame($user->id, session('login.id'));
    }

    public function test_a_provider_that_is_not_configured_does_not_exist(): void
    {
        config(['services.apple.client_id' => null]);
        $this->get('/auth/apple/redirect')->assertNotFound();
        $this->get('/auth/github/redirect')->assertNotFound();
        $this->get('/login')->assertSee('Continue with Google')->assertDontSee('Continue with Apple');
    }

    public function test_a_half_configured_provider_shows_no_button(): void
    {
        config(['services.google.client_secret' => null, 'services.apple.key_id' => null]);
        $this->get('/login')->assertDontSee('Continue with Google')->assertDontSee('Continue with Apple');
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_apples_form_post_callback_is_accepted_without_a_csrf_token(): void
    {
        // Apple returns by POSTing a form from appleid.apple.com, which cannot
        // carry our CSRF token; the state parameter protects that round trip.
        $this->providerReturns('apple', 'a-2', 'new@example.com', verified: true);
        $this->post('/auth/apple/callback')->assertRedirect(route('dashboard'));
    }
}
