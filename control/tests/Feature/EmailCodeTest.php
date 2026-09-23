<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailWithCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Email confirmation by a six-digit code typed on the site (the link in the
 * same message still works). The code is stored only as a hash, expires,
 * works once, and allows five wrong tries before a new one must be sent.
 */
class EmailCodeTest extends TestCase
{
    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fleet.mail_enabled' => true]);
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('0000000000000000000000000000000000A:1')]);
        Notification::fake();
    }

    private function signUp(): User
    {
        $this->post('/register', ['name' => 'C', 'email' => 'c@example.com',
            'password' => 'a-strong-password-42', 'password_confirmation' => 'a-strong-password-42'])
            ->assertRedirect(route('verification.notice'));
        $user = User::where('email', 'c@example.com')->first();
        $this->code = $this->sentCode($user);

        return $user;
    }

    private function sentCode(User $user): string
    {
        $code = null;
        Notification::assertSentTo($user, VerifyEmailWithCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    public function test_signup_emails_a_six_digit_code_stored_only_as_a_hash(): void
    {
        $user = $this->signUp();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->code);
        $this->assertNotSame($this->code, $user->fresh()->email_code_hash);
        $this->assertStringNotContainsString($this->code, (string) $user->fresh()->email_code_hash);
    }

    public function test_the_right_code_confirms_the_address_once(): void
    {
        $user = $this->signUp();

        $this->post(route('verification.code'), ['code' => $this->code])->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertNull($user->fresh()->email_code_hash, 'A used code must not remain usable.');
    }

    public function test_spaces_and_dashes_a_person_types_are_ignored(): void
    {
        $user = $this->signUp();
        $typed = substr($this->code, 0, 3).' - '.substr($this->code, 3);

        $this->post(route('verification.code'), ['code' => $typed])->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_five_wrong_codes_lock_it_until_a_new_one_is_sent(): void
    {
        $user = $this->signUp();
        $wrong = $this->code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('verification.code'), ['code' => $wrong])->assertSessionHasErrors('code');
        }
        // Now even the right code is refused: guessing is capped per code.
        $this->post(route('verification.code'), ['code' => $this->code])
            ->assertSessionHasErrors(['code' => 'Too many wrong codes. Send a new one.']);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());

        // A new code works, and the old one does not.
        $this->post(route('verification.send'));
        $fresh = collect(Notification::sent($user, VerifyEmailWithCode::class))->last()->code;
        if ($fresh !== $this->code) {
            $this->post(route('verification.code'), ['code' => $this->code])->assertSessionHasErrors('code');
        }
        $this->post(route('verification.code'), ['code' => $fresh])->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->signUp();
        $this->travel(16)->minutes();

        $this->post(route('verification.code'), ['code' => $this->code])
            ->assertSessionHasErrors(['code' => 'That code has expired. Send a new one.']);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_message_carries_the_code_and_the_link(): void
    {
        $user = $this->signUp();
        $mail = collect(Notification::sent($user, VerifyEmailWithCode::class))->last()->toMail($user);

        // Shown spaced for reading ("123 456"); typing it either way works.
        $spaced = substr($this->code, 0, 3).' '.substr($this->code, 3);
        $this->assertStringContainsString($spaced, implode("\n", $mail->introLines));
        $this->assertStringContainsString($spaced, $mail->subject);
        $this->assertStringContainsString('/email/verify/', $mail->actionUrl);
    }
}
