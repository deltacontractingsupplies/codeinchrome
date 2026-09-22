<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Security\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    /**
     * The secret lives in the SESSION until the first code proves the app has
     * it. Saving it straight to the account would enable 2FA with a secret the
     * customer may never have scanned - and lock them out.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at) {
            return redirect()->route('account');
        }
        $secret = $request->session()->get('two_factor.pending') ?? Totp::newSecret();
        $request->session()->put('two_factor.pending', $secret);

        $uri = Totp::provisioningUri($secret, $user->email);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($uri);

        return view('two-factor.setup', ['qr' => $svg, 'secret' => trim(chunk_split($secret, 4, ' '))]);
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('two_factor.pending');
        if (! $secret) {
            return redirect()->route('two-factor.setup');
        }
        $step = Totp::verify($secret, $request->input('code'));
        if ($step === null) {
            return back()->withErrors(['code' => 'That code does not match. Check the time on your phone and try the current code.']);
        }

        $codes = collect(range(1, 8))->map(fn () => Str::lower(Str::random(5) . '-' . Str::random(5)))->all();
        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();
        $request->session()->forget('two_factor.pending');

        // Shown exactly once. Only their hashes are kept.
        return view('two-factor.recovery', ['codes' => $codes]);
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $request->user()->forceFill([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
        ])->save();

        return back()->with('status', 'Two-factor authentication is off.');
    }

    // ── the sign-in challenge ───────────────────────────────────────────────

    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->session()->has('login.id') ? view('two-factor.challenge') : redirect()->route('login');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = User::find($request->session()->get('login.id'));
        // The half-signed-in state expires: a password typed an hour ago on a
        // shared computer must not still be one code away from an account.
        if (! $user || $request->session()->get('login.at', 0) < now()->subMinutes(10)->timestamp) {
            $request->session()->forget(['login.id', 'login.remember', 'login.at']);

            return redirect()->route('login')->withErrors(['email' => 'That sign-in expired. Please sign in again.']);
        }

        // Five tries a minute PER ACCOUNT, not per IP: six digits is a million
        // codes, and this is what makes guessing them impossible - while a
        // lockout on one account does not lock out everyone else behind the
        // same office network. Cleared on success.
        $limiter = 'two-factor:' . $user->id;
        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            return back()->withErrors(['code' => 'Too many attempts. Wait ' . RateLimiter::availableIn($limiter) . ' seconds and try again.']);
        }
        RateLimiter::hit($limiter, 60);

        $code = trim((string) $request->input('code'));
        $recovery = trim(Str::lower((string) $request->input('recovery_code')));
        $ok = false;

        if ($code !== '') {
            $step = Totp::verify($user->two_factor_secret, $code);
            // Refuse a step already used: a code read over someone's shoulder
            // is still valid for up to a minute and must not work twice.
            if ($step !== null && $step <= (int) $user->two_factor_last_step) {
                // Only reachable with a code that WAS right, so saying so
                // tells an attacker nothing - and a person who just turned
                // two-factor on and signed straight back in needs to know to
                // wait for the next code, not that theirs is wrong.
                return back()->withErrors(['code' => 'That code has already been used. Wait for the next one in your app.']);
            }
            if ($step !== null) {
                $user->forceFill(['two_factor_last_step' => $step])->save();
                $ok = true;
            }
        } elseif ($recovery !== '') {
            $hashes = $user->two_factor_recovery_codes ?? [];
            foreach ($hashes as $i => $hash) {
                if (Hash::check($recovery, $hash)) {
                    unset($hashes[$i]); // one use each
                    $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                    $ok = true;
                    break;
                }
            }
        }

        if (! $ok) {
            return back()->withErrors(['code' => 'That code did not work.']);
        }

        RateLimiter::clear($limiter);
        $remember = (bool) $request->session()->pull('login.remember');
        $request->session()->forget(['login.id', 'login.at']);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
