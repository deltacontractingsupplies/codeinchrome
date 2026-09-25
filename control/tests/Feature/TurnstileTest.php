<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cloudflare Turnstile on sign-up, email sign-in and reset mail: off until both keys are set. */
class TurnstileTest extends TestCase
{
    /** What Cloudflare's siteverify answers: true, false, or 'down'. */
    private bool|string $verdict = true;

    /** @var list<array> every siteverify request's form fields */
    private array $verified = [];

    private function turnOn(): void
    {
        config(['services.turnstile.site_key' => '1x00000000000000000000AA', 'services.turnstile.secret' => 'test-secret']);
        Http::fake([
            'challenges.cloudflare.com/*' => function (ClientRequest $r) {
                if ($this->verdict === 'down') {
                    throw new ConnectionException('unreachable');
                }
                $this->verified[] = $r->data();

                return Http::response(['success' => $this->verdict]);
            },
            'api.pwnedpasswords.com/*' => Http::response(''),
        ]);
    }

    public function test_off_by_default_the_forms_and_the_policy_are_unchanged(): void
    {
        foreach (['/login', '/register'] as $page) {
            $res = $this->get($page)->assertOk()->assertDontSee('cf-turnstile', false);
            $this->assertStringNotContainsString('challenges.cloudflare.com', $res->headers->get('Content-Security-Policy'));
        }
    }

    public function test_on_the_widget_is_shown_and_only_those_pages_allow_its_origin(): void
    {
        $this->turnOn();

        foreach (['/login', '/register'] as $page) {
            $res = $this->get($page)->assertOk()
                ->assertSee('class="cf-turnstile" data-sitekey="1x00000000000000000000AA"', false)
                ->assertSee('src="https://challenges.cloudflare.com/turnstile/v0/api.js"', false);
            $csp = $res->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("script-src 'self' https://challenges.cloudflare.com", $csp);
            $this->assertStringContainsString('frame-src https://challenges.cloudflare.com', $csp);
        }
        $home = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('challenges.cloudflare.com', $home, 'a page without the widget keeps the strict policy');
    }

    public function test_sign_in_needs_a_token_cloudflare_confirms(): void
    {
        $this->turnOn();
        $user = User::factory()->create(['email' => 'person@gmail.com', 'password' => bcrypt('correct horse battery 9')]);
        $form = ['email' => 'person@gmail.com', 'password' => 'correct horse battery 9'];

        $this->post('/login', $form)->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();

        $this->verdict = false;
        $this->post('/login', $form + ['cf-turnstile-response' => 'bad'])->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest();

        $this->verdict = 'down';
        $this->post('/login', $form + ['cf-turnstile-response' => 'tok'])->assertSessionHasErrors('cf-turnstile-response');
        $this->assertGuest(); // fails closed when Cloudflare cannot confirm

        $this->verdict = true;
        $this->post('/login', $form + ['cf-turnstile-response' => 'good-token']);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('test-secret', end($this->verified)['secret']);
        $this->assertSame('good-token', end($this->verified)['response']);
    }

    public function test_sign_up_is_refused_without_a_confirmed_token(): void
    {
        $this->turnOn();
        $form = ['name' => 'P', 'email' => 'new.person@gmail.com', 'password' => 'a-long-enough-pass-9Q', 'password_confirmation' => 'a-long-enough-pass-9Q']; // gitleaks:allow - a throwaway test password

        $this->post('/register', $form)->assertSessionHasErrors('cf-turnstile-response');
        $this->assertDatabaseMissing('users', ['email' => 'new.person@gmail.com']);

        $this->post('/register', $form + ['cf-turnstile-response' => 'good-token']);
        $this->assertDatabaseHas('users', ['email' => 'new.person@gmail.com']);
    }

    public function test_the_e2e_suites_reserved_addresses_skip_it_and_nothing_else_does(): void
    {
        $this->turnOn();
        config(['signup.test_domain' => 'codeinchrome.test', 'signup.test_secret' => 'shh']);
        $this->withHeader('X-CIC-E2E', \App\Auth\TestSuite::header('shh'));
        $form = fn ($email) => ['name' => 'P', 'email' => $email, 'password' => 'a-long-enough-pass-9Q', 'password_confirmation' => 'a-long-enough-pass-9Q']; // gitleaks:allow - a throwaway test password

        $this->post('/register', $form('e2e-1@codeinchrome.test'));
        $this->assertDatabaseHas('users', ['email' => 'e2e-1@codeinchrome.test']);
        $this->assertSame([], $this->verified);

        auth()->logout();
        $this->post('/register', $form('e2e@codeinchrome.test.evil.example'))->assertSessionHasErrors('cf-turnstile-response');

        config(['signup.test_domain' => null]);
        $this->post('/register', $form('e2e-2@codeinchrome.test'))->assertSessionHasErrors('cf-turnstile-response');
    }
}
