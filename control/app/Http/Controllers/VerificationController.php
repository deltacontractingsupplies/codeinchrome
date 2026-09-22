<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        Audit::record('email.verified');

        return redirect()->route('dashboard')->with('status', 'Email confirmed.');
    }

    public function send(Request $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'A new confirmation link is on its way.');
    }
}
