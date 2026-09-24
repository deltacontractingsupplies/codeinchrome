<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * Who may create an account (owner, 2026-09-25): Google or Apple sign-in, or
 * an email sign-up from a trusted provider only. config/signup.php.
 */
class SignupTest extends TestCase
{
    private function register(string $email)
    {
        return $this->post('/register', ['name' => 'New Person', 'email' => $email,
            'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // The production rule, whatever phpunit.xml allows for other tests.
        config(['signup.email_domains' => ['gmail.com', 'googlemail.com'], 'signup.test_domain' => null]);
    }

    public function test_an_address_at_a_trusted_provider_can_sign_up(): void
    {
        $this->register('Someone.New@Gmail.com')->assertSessionHasNoErrors();
        // Kept in lower case, with the mailbox it reaches.
        $user = User::where('email', 'someone.new@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('someonenew@gmail.com', $user->email_canonical);
    }

    public function test_any_other_domain_is_refused_and_told_to_use_google_or_apple(): void
    {
        foreach (['someone@example.com', 'x@mailinator.com', 'boss@my-company.io', 'a@gmail.com.evil.test', 'nodomain'] as $email) {
            $this->register($email)->assertSessionHasErrors(['email']);
            $this->assertNull(User::where('email', $email)->first(), "$email was accepted");
        }
        $this->register('someone@example.com')->assertSessionHasErrors([
            'email' => 'To keep abuse out, new accounts use Google or Apple sign-in, or an email address from Gmail.',
        ]);
    }

    public function test_the_e2e_suites_reserved_domain_only_where_configured(): void
    {
        $this->register('run-1@codeinchrome.test')->assertSessionHasErrors(['email']);
        config(['signup.test_domain' => 'codeinchrome.test']);
        $this->register('run-2@codeinchrome.test')->assertSessionHasNoErrors();
    }

    public function test_existing_accounts_at_other_domains_still_sign_in(): void
    {
        $user = User::factory()->create(['email' => 'old@company.example', 'password' => bcrypt('long-enough-password')]);
        $this->post('/login', ['email' => 'old@company.example', 'password' => 'long-enough-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_sign_up_page_says_which_addresses_work_before_anyone_types(): void
    {
        $this->get('/register')->assertOk()->assertSee('data-signup-domains', false)
            ->assertSee('gmail.com')->assertSee('use Google or Apple above');
    }

    public function test_one_gmail_inbox_is_one_account_whatever_the_spelling(): void
    {
        $this->register('pat.example@gmail.com')->assertSessionHasNoErrors();
        auth()->logout();
        foreach (['patexample@gmail.com', 'Pat.Example+free2@gmail.com', 'p.a.t.example@googlemail.com', 'PATEXAMPLE@GMAIL.COM'] as $alias) {
            $this->register($alias)->assertSessionHasErrors(['email' => 'An account already uses this email address.']);
        }
        $this->assertSame(1, User::count());
    }

    public function test_sign_in_and_reset_ignore_the_case_of_the_address(): void
    {
        $this->register('Mixed.Case@Gmail.com')->assertSessionHasNoErrors();
        auth()->logout();
        $this->post('/login', ['email' => 'MIXED.case@gmail.COM', 'password' => 'correct-horse-battery-9']);
        $this->assertAuthenticated();
    }
}
