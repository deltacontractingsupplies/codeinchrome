<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
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
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::signupDomainAllowed((string) $value)) {
                    $fail(self::signupDomainMessage());
                } elseif (User::where('email_canonical', \App\Auth\EmailIdentity::canonical((string) $value))->exists()) {
                    // The same inbox under another spelling (dots, +tag, googlemail.com).
                    $fail('An account already uses this email address.');
                }
            }],
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
        $user->startTrial();

        Auth::login($user);
        $request->session()->regenerate();
        Audit::record('account.created', $user, actor: $user);

        if (config('fleet.mail_enabled')) {
            $user->sendEmailVerificationNotification();

            return redirect()->route('verification.notice');
        }

        return redirect()->route('dashboard')->with('status', 'Welcome. Create your first site below.');
    }

    /** config/signup.php: the providers an email sign-up may use. */
    public static function signupDomainAllowed(string $email): bool
    {
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        $allowed = config('signup.email_domains', []);
        if ($test = config('signup.test_domain')) {
            $allowed[] = strtolower($test);
        }

        return $domain !== '' && in_array($domain, $allowed, true);
    }

    public static function signupDomainMessage(): string
    {
        $providers = collect(config('signup.email_domains', []))->reject(fn ($d) => $d === 'googlemail.com')
            ->map(fn ($d) => ucfirst(strtok($d, '.')))->unique()->implode(', ');

        return 'To keep abuse out, new accounts use Google or Apple sign-in, or an email address from '.($providers ?: 'a trusted provider').'.';
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Validate first, sign in second: an account with two-factor on must
        // not be signed in by the password alone, even for one request.
        if (! Auth::validate($credentials)) {
            // Recorded against the account if it exists - visible only to
            // that account's owner, so it reveals nothing to the attacker.
            if ($target = \App\Models\User::where('email', $credentials['email'])->first()) {
                Audit::record('auth.login_failed', $target, actor: $target);
            }
            // One message for both a wrong password and an unknown address.
            // Distinguishing them tells an attacker which emails have accounts.
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Those details do not match an account.']);
        }

        $user = \App\Models\User::where('email', $credentials['email'])->first();
        if ($user?->banned_at) {
            return back()->withErrors(['email' => \App\Http\Middleware\BannedAccount::MESSAGE]);
        }
        if ($user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put(['login.id' => $user->id, 'login.remember' => true, 'login.at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, true);
        Audit::record('auth.login', $user, actor: $user);

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
