<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Sign in with Google or Apple.
 *
 *   - An identity already linked signs in to its account, whatever its email
 *     says today: the provider's id, not the address, is the key.
 *   - A new identity joins an existing account ONLY when the provider says it
 *     verified the address. Otherwise anyone could claim someone's account by
 *     typing their email into a provider that does not check.
 *   - Two-factor still applies. The provider stands in for the password, not
 *     for the second factor.
 */
class SocialLoginController extends Controller
{
    public const PROVIDERS = ['google', 'apple'];

    /** Providers that are configured, in display order. */
    public static function enabled(): array
    {
        // Everything a provider needs, or its button does not appear: a
        // half-configured provider would send people to a sign-in that fails.
        $needs = [
            'google' => ['client_id', 'client_secret'],
            'apple' => ['client_id', 'team_id', 'key_id', 'private_key'],
        ];

        return array_values(array_filter(self::PROVIDERS, fn ($p) => collect($needs[$p])
            ->every(fn ($key) => filled(config("services.$p.$key")))));
    }

    private function driver(string $provider): Provider
    {
        abort_unless(in_array($provider, self::enabled(), true), 404);
        $driver = Socialite::driver($provider);

        // Apple returns by POSTing a form from appleid.apple.com. Our session
        // cookie is SameSite=Lax, so it is not sent on that cross-site POST
        // and the usual session state cannot be checked. Instead the nonce
        // rides in an encrypted SameSite=None cookie bound to this browser,
        // and is verified against Apple's signed identity token.
        if ($provider === 'apple' && method_exists($driver, 'stateless')) {
            $driver = $driver->stateless()->cookieNonce();
        }

        return $driver;
    }

    public function redirect(string $provider): SymfonyRedirect
    {
        $driver = $this->driver($provider);
        if ($provider === 'google') {
            $driver->scopes(['openid', 'email', 'profile']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        try {
            $identity = $this->driver($provider)->user();
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return redirect()->route('login')->withErrors(['email' => 'Signing in with '.ucfirst($provider).' did not complete. Please try again.']);
        }

        $email = strtolower((string) $identity->getEmail());
        $verified = filter_var($identity->getRaw()['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = DB::transaction(function () use ($provider, $identity, $email, $verified) {
            $link = SocialAccount::where(['provider' => $provider, 'provider_user_id' => (string) $identity->getId()])->first();
            if ($link) {
                return $link->user;
            }
            if ($email === '' || ! $verified) {
                return null;
            }

            // The same mailbox under any spelling (App\Auth\EmailIdentity).
            $user = User::where('email_canonical', \App\Auth\EmailIdentity::canonical($email))->first();
            if (! $user) {
                $user = User::create([
                    'name' => $identity->getName() ?: Str::before($email, '@'),
                    'email' => $email,
                    // Never typed by anyone. The owner can set a password of
                    // their own with "forgot password" if they ever want one.
                    'password' => Str::random(64),
                    'plan' => 'free',
                ]);
                $user->startTrial();
                Audit::record('account.created', $user, actor: $user, detail: ['via' => $provider]);
            }
            if (! $user->hasVerifiedEmail()) {
                // An UNVERIFIED account is claimed by whoever proves the
                // address. Anyone could have made it - with their own
                // password, even two-factor - hoping the address's owner
                // would sign in with Google or Apple one day and hand them a
                // verified account (the security audit, 2026-09-25). So every
                // credential on it is dropped first: a new random password
                // (which also signs out every other session - auth.session),
                // no remember token, no two-factor.
                $user->forceFill([
                    'password' => Str::random(64),
                    'remember_token' => Str::random(60),
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                    'two_factor_last_step' => null,
                ])->save();
                Audit::record('auth.unverified_account_claimed', $user, actor: $user, detail: ['via' => $provider]);
                $user->markEmailAsVerified();
            }
            SocialAccount::create(['user_id' => $user->id, 'provider' => $provider,
                'provider_user_id' => (string) $identity->getId(), 'email' => $email]);

            return $user;
        });

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => ucfirst($provider).' did not confirm that email address, so it cannot be used to sign in here.']);
        }
        if ($user->banned_at) {
            return redirect()->route('login')->withErrors(['email' => \App\Http\Middleware\BannedAccount::MESSAGE]);
        }

        $request->session()->regenerate();
        if ($user->two_factor_confirmed_at) {
            $request->session()->put(['login.id' => $user->id, 'login.remember' => true, 'login.at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, true);
        Audit::record('auth.login', $user, actor: $user, detail: ['via' => $provider]);

        return redirect()->intended(route('dashboard'));
    }
}
