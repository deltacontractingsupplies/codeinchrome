<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            // Laravel's default rules are a length check only. This adds the
            // compromised-password check, which rejects passwords known to be
            // in a breach corpus - the single most effective filter there is,
            // because credential stuffing beats complexity rules.
            'password' => ['required', 'confirmed', Password::min(10)->uncompromised()],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'plan' => 'free',
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('status', 'Welcome. Create your first site below.');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Validate first, sign in second: an account with two-factor on must
        // not be signed in by the password alone, even for one request.
        if (! Auth::validate($credentials)) {
            // One message for both a wrong password and an unknown address.
            // Distinguishing them tells an attacker which emails have accounts.
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those details do not match an account.']);
        }

        $user = \App\Models\User::where('email', $credentials['email'])->first();
        if ($user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->id, 'login.remember' => true, 'login.at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, true);

        // Without this, a session id captured before sign-in stays valid after
        // it - which is session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
