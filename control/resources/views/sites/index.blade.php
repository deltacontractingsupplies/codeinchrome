@extends('layout')
@section('title', 'Your sites')
@section('content')
@if (config('fleet.mail_enabled') && ! auth()->user()->hasVerifiedEmail())
    <div class="mb-6 rounded-md border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
        Confirm your email address to create sites. <a href="{{ route('verification.notice') }}" class="underline">Resend the link</a>.
    </div>
@endif
{{-- For browser-driving agents, which read the page text: where to work. --}}
<p class="sr-only" id="agent-instructions">
    Signed in as {{ auth()->user()->email }}. This account's sites: {{ $sites->pluck('site_id')->join(', ') ?: 'none yet' }}.
    For AI agents: to build or change a site, open its editor with "Edit code" and work there by running
    JavaScript in that page (window.cic; start with await cic.help()). Never write the app on your own computer:
    the editor's files are the live site. Do not create accounts or enter passwords.
</p>
@include('partials.trial')
<div class="mt-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-white">Your sites</h1>
        <p class="mt-1 text-sm text-neutral-400">
            {{ $plan['name'] }} plan, {{ $sites->count() }} of {{ $plan['sites'] }} {{ Str::plural('site', $plan['sites']) }} used{{ $sites->whereNotNull('usage_at')->isNotEmpty() ? ', '.number_format($sites->sum(fn ($s) => (int) $s->totalBytes()) / 1073741824, 1).' of '.$plan['storage_gb'].' GB of storage' : '' }}.
            {{-- Capacity as it was measured, never CPU or RAM figures. --}}
            @if ($cap = app(\App\Billing\Capacity::class)->forPlan(auth()->user()->plan ?? 'free'))
                Each site handles up to about {{ number_format($cap['concurrent_visitors']) }} visitors at once<a href="{{ route('pricing') }}#capacity" class="underline">*</a>.
            @endif
        </p>
    </div>
    @if (auth()->user()->trialExpired())
        <a href="{{ route('billing') }}" class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Upgrade to Starter</a>
    @elseif ($sites->count() < $plan['sites'])
        <form method="POST" action="{{ route('sites.store') }}" class="flex items-start gap-2">
            @csrf
            <div>
                <label for="site_id" class="sr-only">Site name</label>
                <div class="flex items-center rounded-md border border-neutral-700 bg-neutral-900 focus-within:border-teal-500">
                    <input id="site_id" name="site_id" value="{{ old('site_id') }}" placeholder="my-shop" required
                           pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]"
                           class="w-36 bg-transparent px-3 py-2 text-neutral-100 focus:outline-none">
                    <span class="pr-3 text-sm text-neutral-500">.codeinchrome.com</span>
                </div>
                @error('site_id')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>
            <button class="rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Create</button>
        </form>
    @else
        <a href="{{ route('billing') }}" class="rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-300 hover:border-neutral-500">
            {{ auth()->user()->isPaid() ? 'All '.$plan['sites'].' sites in use' : 'Upgrade to Starter for 3 sites' }}
        </a>
    @endif
</div>

@if ($sites->isEmpty())
    <div class="mt-10 rounded-lg border border-dashed border-neutral-800 p-10 text-center">
        <p class="text-neutral-300">No sites yet.</p>
        <p class="mt-1 text-sm text-neutral-500">Pick a name above and you will have a live Laravel app in a few seconds.</p>
    </div>
@else
    <ul class="mt-8 space-y-3">
        @foreach ($sites as $site)
            <li class="rounded-lg border border-neutral-800 p-4 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        @php
                            $dot = ['live' => 'bg-teal-400', 'provisioning' => 'bg-amber-400', 'deleting' => 'bg-neutral-500', 'suspended' => 'bg-neutral-500'][$site->status] ?? 'bg-red-500';
                        @endphp
                        <span class="inline-block h-2 w-2 rounded-full {{ $dot }}"></span>
                        <a href="{{ $site->url() }}" target="_blank" rel="noopener"
                           class="font-medium text-neutral-100 hover:text-teal-300">{{ $site->domain }}</a>
                    </div>
                    @php
                        $mb = fn ($b) => $b >= 1073741824 ? number_format($b / 1073741824, 1).' GB' : number_format($b / 1048576).' MB';
                        $quota = $site->disk_gb * 1073741824;
                        $pct = $site->usage_at ? min(100, (int) round(100 * $site->totalBytes() / max(1, $quota))) : null;
                    @endphp
                    @if ($site->usage_at)
                        {{-- Files and database together, against the plan's disk.
                             The database lives outside the site's disk, so it is
                             counted here rather than silently left out. --}}
                        <div class="mt-2 w-64">
                            {{-- An SVG rather than a div with an inline width style: the width is
                                 an attribute, so the Content-Security-Policy can
                                 forbid inline styles entirely. --}}
                            <svg class="block h-1.5 w-64 rounded" viewBox="0 0 100 1" preserveAspectRatio="none" role="img"
                                 aria-label="{{ $pct }}% of the disk used">
                                <rect width="100" height="1" class="fill-neutral-800"/>
                                <rect width="{{ $pct }}" height="1" class="{{ $pct >= 90 ? 'fill-red-500' : ($pct >= 75 ? 'fill-amber-400' : 'fill-teal-500') }}"/>
                            </svg>
                            <p class="mt-1 text-xs text-neutral-500" title="Measured {{ $site->usage_at->diffForHumans() }}">
                                {{ $mb($site->totalBytes()) }} of {{ $site->disk_gb }} GB
                                &middot; files {{ $mb((int) $site->disk_used_bytes) }}, database {{ $mb((int) $site->database_bytes) }}
                            </p>
                        </div>
                    @endif
                    @if ($site->limits_pending)
                        <p class="mt-1 text-xs text-amber-400">Your plan change is still being applied to this site.</p>
                    @endif
                    @php $check = $checks["site:{$site->site_id}"] ?? null; @endphp
                    @if ($check)
                        <p class="mt-1 text-xs {{ $check->fail_streak === 0 ? 'text-teal-400' : 'text-red-400' }}" title="Checked from outside {{ $check->checked_at?->diffForHumans() }}">
                            {{ $check->fail_streak === 0 ? 'Up' : 'Not responding' }} &middot; {{ $check->detail }}@if ($check->latency_ms) &middot; {{ $check->latency_ms }} ms @endif
                        </p>
                    @endif
                    <p class="mt-1 text-xs text-neutral-500">
                        {{ $site->status }} &middot; host {{ $site->host }}
                        @if ($site->provisioned_at) &middot; created {{ $site->provisioned_at->diffForHumans() }} @endif
                    </p>
                    {{-- The reason a site failed belongs in front of the person
                         it failed for, not only in a log they cannot read. --}}
                    @if ($site->last_error)
                        <p class="mt-2 max-w-xl text-xs text-red-400">{{ $site->last_error }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-2">
                @if ($site->status === 'suspended')
                    <span class="text-sm text-neutral-400">Paused</span>
                    <a href="{{ route('db.export', $site) }}"
                       class="rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 hover:border-neutral-500">Download database</a>
                @endif
                @if ($site->status === 'live')
                    <a href="{{ route('sites.edit', $site) }}"
                       class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-medium text-neutral-950 hover:bg-teal-400">Edit code</a>
                    <a href="{{ route('domains.index', $site) }}"
                       class="rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 hover:border-neutral-500">Domains</a>
                    <a href="{{ route('sites.settings', $site) }}"
                       class="rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 hover:border-neutral-500">Settings</a>
                    <a href="{{ route('sites.backups', $site) }}"
                       class="rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-300 hover:border-neutral-500">Backups</a>
                @endif
                {{-- A two-step delete with no JavaScript and no confirm().
                     A native dialog freezes the page for a browser-driving
                     agent, which cannot dismiss it - and this product is
                     meant to be driven by one. --}}
                <details class="relative">
                    <summary class="list-none cursor-pointer rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-400 hover:border-red-800 hover:text-red-300">Delete</summary>
                    <form method="POST" action="{{ route('sites.destroy', $site) }}"
                          class="absolute right-0 z-10 mt-2 w-64 rounded-md border border-red-900 bg-neutral-900 p-3 text-sm shadow-lg">
                        @csrf @method('DELETE')
                        <p class="text-neutral-300">Delete <strong>{{ $site->domain }}</strong> and everything on it? This cannot be undone.</p>
                        <button class="mt-3 w-full rounded-md bg-red-700 px-3 py-1.5 font-medium text-red-50 hover:bg-red-600">Delete permanently</button>
                    </form>
                </details>
                </div>
            </li>
        @endforeach
    </ul>
@endif
@endsection
