<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class VerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : view('auth.verify-email');
    }

    /** The signed link from the email. Laravel checks the signature and the hash. */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();
        $request->user()->forceFill(['email_code_hash' => null, 'email_code_expires_at' => null, 'email_code_attempts' => 0])->save();
        Audit::record('email.verified');

        return redirect()->route('dashboard')->with('status', 'Email confirmed.');
    }

    /**
     * The six-digit code from the email. Five wrong tries per code, then a new
     * one must be sent: with a million possible codes, that keeps guessing at
     * five in a million per email - and the route is throttled as well.
     */
    public function code(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return redirect()->route('dashboard');
        }
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $code = preg_replace('/\D/', '', (string) $request->input('code'));

        $fail = fn (string $why) => back()->withErrors(['code' => $why]);
        if (! $user->email_code_hash) {
            return $fail('Send a new code.');
        }
        if ($user->email_code_attempts >= 5) {
            return $fail('Too many wrong codes. Send a new one.');
        }
        if ($user->email_code_expires_at?->isPast()) {
            return $fail('That code has expired. Send a new one.');
        }
        if (strlen($code) !== 6 || ! Hash::check($code, $user->email_code_hash)) {
            $user->increment('email_code_attempts');

            return $fail('That code is not right.');
        }

        $user->markEmailAsVerified();
        $user->forceFill(['email_code_hash' => null, 'email_code_expires_at' => null, 'email_code_attempts' => 0])->save();
        Audit::record('email.verified', $user, actor: $user, detail: ['by' => 'code']);

        return redirect()->route('dashboard')->with('status', 'Email confirmed.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A new code is on its way.');
    }
}
