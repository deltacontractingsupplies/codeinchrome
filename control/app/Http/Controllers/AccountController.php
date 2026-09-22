<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Fleet\Provisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('account', ['user' => $request->user()]);
    }

    public function activity(Request $request): View
    {
        return view('activity', [
            'events' => \App\Models\AuditEvent::where('account_id', $request->user()->id)->latest('id')->limit(200)->get(),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->uncompromised(), 'different:current_password'],
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        // Every other session signed in with the old password is ended: a
        // password change after a suspected compromise must actually lock the
        // intruder out, not just change what the next login needs.
        Auth::logoutOtherDevices($data['password']);
        Audit::record('password.changed');
        $request->session()->regenerate();

        return back()->with('status', 'Password changed. Every other session has been signed out.');
    }

    /**
     * Delete the account and everything it owns.
     *
     * Refused while a subscription is still active - Lemon Squeezy would go on
     * charging for an account that no longer exists. Every site is removed
     * through its normal path (container, disk, database, DNS); if any part of
     * any site survives, the account is NOT deleted, because it is the only
     * record that something is still out there.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();

        $billing = $user->subscriptions()->latest()->first();
        if ($billing && in_array($billing->status, ['active', 'on_trial', 'past_due'], true)) {
            return back()->with('error', 'Cancel your subscription in the billing portal first, so you are not charged for a deleted account.');
        }

        // Built only when there is a site to remove: it needs DNS credentials,
        // and deleting an account with no sites must not depend on them.
        $provisioner = $user->sites->isNotEmpty() ? Provisioner::make() : null;
        foreach ($user->sites as $site) {
            try {
                $parts = $provisioner->destroy($site);
            } catch (\Throwable $e) {
                return back()->with('error', "Could not remove {$site->domain}: {$e->getMessage()}. Your account was kept.");
            }
            if (in_array('failed', $parts, true)) {
                return back()->with('error', "{$site->domain} was only partly removed. Your account was kept so it can be finished.");
            }
        }

        // Recorded BEFORE the row goes: the history outlives the account.
        Audit::record('account.deleted', $user, actor: $user, detail: ['email_sha256' => hash('sha256', strtolower($user->email))]);
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Your account and all of its sites have been deleted.');
    }
}
