<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailWithCode as VerifyEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);
    }

    public function test_with_mail_on_signup_sends_a_link_and_creating_a_site_waits_for_it(): void
    {
        config(['fleet.mail_enabled' => true]);
        Notification::fake();

        $this->post('/register', ['name' => 'V', 'email' => 'v@example.com',
            'password' => 'a-strong-password-42', 'password_confirmation' => 'a-strong-password-42'])
            ->assertRedirect(route('verification.notice'));

        $user = User::where('email', 'v@example.com')->first();
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->post('/sites', ['site_id' => 'waiting'])->assertRedirect(route('verification.notice'));

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_link_does_not_verify(): void
    {
        config(['fleet.mail_enabled' => true]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('someone-else@example.com')]);

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_without_real_mail_nobody_is_asked_to_verify(): void
    {
        config(['fleet.mail_enabled' => false]);
        Notification::fake();

        $this->post('/register', ['name' => 'N', 'email' => 'n@example.com',
            'password' => 'a-strong-password-42', 'password_confirmation' => 'a-strong-password-42'])
            ->assertRedirect(route('dashboard'));
        Notification::assertNothingSent();
    }
}
