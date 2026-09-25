<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        abort_unless(config('fleet.mail_enabled'), 404);

        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        abort_unless(config('fleet.mail_enabled'), 404);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => ['required', 'email']] + \App\Auth\Turnstile::rules($request->input('email')));

        // At most 3 an hour to one address, whoever asks from wherever: the
        // per-IP limit alone let someone flood a person's inbox from ours
        // (the security audit, 2026-09-25). Same answer either way.
        $key = 'reset-mail:'.sha1($request->input('email'));
        if (! \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 3)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 3600);
            Password::sendResetLink($request->only('email'));
        }

        // The same answer whether or not the address has an account; the reset
        // form must not become a way to find out who is a customer.
        return back()->with('status', 'If that address has an account, a reset link is on its way.');
    }

    public function edit(Request $request, string $token): View
    {
        abort_unless(config('fleet.mail_enabled'), 404);

        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(config('fleet.mail_enabled'), 404);
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->uncompromised()],
        ]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                // A new remember token signs out any "remember me" session
                // left behind by whoever made the reset necessary.
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', 'Password reset. Sign in with the new one.')
            : back()->withErrors(['email' => __($status)]);
    }
}
