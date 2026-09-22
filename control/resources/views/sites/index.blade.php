@extends('layout')
@section('title', 'Your sites')
@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-white">Your sites</h1>
        <p class="mt-1 text-sm text-neutral-400">
            {{ $plan['name'] }} plan &middot; {{ $sites->count() }} of {{ $plan['sites'] }} used
            &middot; {{ $plan['cpu'] }} CPU, {{ $plan['memory'] }} RAM each
        </p>
    </div>
    @if ($sites->count() < $plan['sites'])
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
        <a href="{{ route('home') }}#plans" class="rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-300 hover:border-neutral-500">
            Plan full — upgrade to add more
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
                            $dot = ['live' => 'bg-teal-400', 'provisioning' => 'bg-amber-400', 'deleting' => 'bg-neutral-500'][$site->status] ?? 'bg-red-500';
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
                            <div class="h-1.5 rounded bg-neutral-800">
                                <div class="h-1.5 rounded {{ $pct >= 90 ? 'bg-red-500' : ($pct >= 75 ? 'bg-amber-400' : 'bg-teal-500') }}" style="width: {{ $pct }}%"></div>
                            </div>
                            <p class="mt-1 text-xs text-neutral-500" title="Measured {{ $site->usage_at->diffForHumans() }}">
                                {{ $mb($site->totalBytes()) }} of {{ $site->disk_gb }} GB
                                &middot; files {{ $mb((int) $site->disk_used_bytes) }}, database {{ $mb((int) $site->database_bytes) }}
                            </p>
                        </div>
                    @endif
                    @if ($site->limits_pending)
                        <p class="mt-1 text-xs text-amber-400">Your plan change is still being applied to this site.</p>
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
                @if ($site->status === 'live')
                    <a href="{{ route('sites.edit', $site) }}"
                       class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-medium text-neutral-950 hover:bg-teal-400">Edit code</a>
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
                        <button class="mt-3 w-full rounded-md bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Delete permanently</button>
                    </form>
                </details>
                </div>
            </li>
        @endforeach
    </ul>
@endif
@endsection
