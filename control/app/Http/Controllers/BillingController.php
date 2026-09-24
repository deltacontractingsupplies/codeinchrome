<?php

namespace App\Http\Controllers;

use App\Billing\Checkout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        return view('billing', [
            'current' => $user->plan,
            // What is for sale - and always the plan this account is on, so a
            // customer who already pays sees it even while it is not sold.
            'plans' => \App\Billing\Sales::plans() + [$request->user()->plan => $request->user()->planConfig()],
            'subscription' => $user->subscriptions()->latest()->first(),
        ]);
    }

    public function checkout(Request $request, Checkout $checkout): RedirectResponse
    {
        // Not for sale yet: no checkout is started, whatever is posted.
        if (! \App\Billing\Sales::open()) {
            return back()->with('error', 'Paid plans are not available yet. Your free site keeps running meanwhile.');
        }
        $request->validate(['plan' => ['required', 'string']]);

        try {
            return redirect()->away($checkout->urlFor($request->user(), $request->input('plan')));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Where Lemon Squeezy sends the customer after paying. It proves nothing:
     * the plan changes only when the SIGNED webhook arrives, so this page says
     * the payment is being confirmed rather than that the upgrade happened.
     */
    public function return(): RedirectResponse
    {
        return redirect()->route('billing')->with('status',
            'Thanks. Your payment is being confirmed; your plan updates here as soon as it is.');
    }
}
