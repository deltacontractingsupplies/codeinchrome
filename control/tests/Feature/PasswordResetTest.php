<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    public function test_without_real_mail_there_is_no_reset_at_all(): void
    {
        config(['fleet.mail_enabled' => false]);

        $this->get('/login')->assertDontSee('Forgot your password?');
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'x@example.com'])->assertNotFound();
    }

    public function test_the_full_reset_flow_when_mail_is_configured(): void
    {
        config(['fleet.mail_enabled' => true]);
        Notification::fake();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);
        $user = User::factory()->create(['email' => 'reset@example.com', 'remember_token' => 'old-token']);

        $this->get('/login')->assertSee('Forgot your password?');
        $this->post('/forgot-password', ['email' => 'reset@example.com'])->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->post('/reset-password', ['token' => $token, 'email' => 'reset@example.com',
            'password' => 'a-fresh-password-77', 'password_confirmation' => 'a-fresh-password-77'])->assertRedirect(route('login'));

        $this->assertTrue(\Hash::check('a-fresh-password-77', $user->fresh()->password));
        $this->assertNotSame('old-token', $user->fresh()->remember_token, 'Old "remember me" sessions survive a reset.');

        // The token is single-use.
        $this->post('/reset-password', ['token' => $token, 'email' => 'reset@example.com',
            'password' => 'another-password-88', 'password_confirmation' => 'another-password-88'])->assertSessionHasErrors('email');
    }

    public function test_an_unknown_address_gets_the_same_answer(): void
    {
        config(['fleet.mail_enabled' => true]);
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', 'If that address has an account, a reset link is on its way.');
        Notification::assertNothingSent();
    }
}
