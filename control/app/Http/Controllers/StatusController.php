<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The operator's view of the fleet. 404 to everyone else: it does not exist for them. */
class StatusController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()->isOperator(), 404);

        return view('status', [
            'monitors' => Monitor::orderByDesc('fail_streak')->orderBy('key')->get(),
            'open' => Incident::whereNull('resolved_at')->orderBy('started_at')->get(),
            'recent' => Incident::whereNotNull('resolved_at')->latest('resolved_at')->limit(20)->get(),
            'webhook' => (bool) config('fleet.alert_webhook'),
        ]);
    }
}
