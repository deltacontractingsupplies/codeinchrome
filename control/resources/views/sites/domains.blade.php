@extends('layout')
@section('title', 'Domains — ' . $site->site_id)
@section('content')
<a href="{{ route('dashboard') }}" class="text-sm text-neutral-500 hover:text-neutral-300">← Your sites</a>
<h1 class="mt-3 text-2xl font-semibold text-white">Domains for {{ $site->site_id }}</h1>
<p class="mt-1 text-sm text-neutral-400">
    Always available at <a href="{{ $site->url() }}" class="text-teal-400" target="_blank" rel="noopener">{{ $site->domain }}</a>.
</p>

@if (! $allowed)
    <div class="mt-8 rounded-lg border border-neutral-800 p-5 text-sm text-neutral-300">
        Custom domains are included from the Starter plan. <a href="{{ route('home') }}#plans" class="text-teal-400">See plans</a>.
    </div>
@else
    <form method="POST" action="{{ route('domains.store', $site) }}" class="mt-8 flex flex-wrap items-start gap-2">
        @csrf
        <div>
            <label for="domain" class="sr-only">Domain</label>
            <input id="domain" name="domain" value="{{ old('domain') }}" placeholder="shop.example.com" required
                   class="w-72 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
            @error('domain')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
        <button class="rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Add domain</button>
    </form>

    <ul class="mt-8 space-y-4">
        @forelse ($domains as $d)
            <li class="rounded-lg border border-neutral-800 p-4" data-domain="{{ $d->domain }}">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-2 w-2 rounded-full {{ $d->verified_at ? 'bg-teal-400' : 'bg-amber-400' }}"></span>
                        <span class="font-medium text-neutral-100">{{ $d->domain }}</span>
                        <span class="text-xs text-neutral-500">{{ $d->verified_at ? 'attached' : 'waiting for DNS' }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @unless ($d->verified_at)
                            <form method="POST" action="{{ route('domains.verify', [$site, $d]) }}">@csrf
                                <button class="rounded-md bg-teal-500 px-3 py-1.5 text-sm font-medium text-neutral-950 hover:bg-teal-400">Verify</button>
                            </form>
                        @endunless
                        <details class="relative">
                            <summary class="list-none cursor-pointer rounded-md border border-neutral-700 px-3 py-1.5 text-sm text-neutral-400 hover:border-red-800 hover:text-red-300">Remove</summary>
                            <form method="POST" action="{{ route('domains.destroy', [$site, $d]) }}"
                                  class="absolute right-0 z-10 mt-2 w-64 rounded-md border border-red-900 bg-neutral-900 p-3 text-sm shadow-lg">
                                @csrf @method('DELETE')
                                <p class="text-neutral-300">Stop serving your site at <strong>{{ $d->domain }}</strong>?</p>
                                <button class="mt-3 w-full rounded-md bg-red-700 px-3 py-1.5 font-medium text-white hover:bg-red-600">Remove domain</button>
                            </form>
                        </details>
                    </div>
                </div>

                @unless ($d->verified_at)
                    <p class="mt-4 text-sm text-neutral-400">Add these two records at your DNS provider, then press Verify:</p>
                    <table class="mt-2 w-full text-left font-mono text-xs">
                        <tr class="text-neutral-500"><th class="py-1 pr-4 font-normal">Type</th><th class="pr-4 font-normal">Name</th><th class="font-normal">Value</th></tr>
                        <tr class="text-neutral-200"><td class="py-1 pr-4">TXT</td><td class="pr-4 select-all" data-txt-name>{{ $d->challengeName() }}</td><td class="select-all break-all" data-txt-value>{{ $d->token }}</td></tr>
                        <tr class="text-neutral-200"><td class="py-1 pr-4">A</td><td class="pr-4 select-all">{{ $d->domain }}</td><td class="select-all" data-a-value>{{ $hostIp }}</td></tr>
                    </table>
                    <p class="mt-2 text-xs text-neutral-500">The TXT record proves the domain is yours; it is never served. The A record sends visitors to your site.</p>
                    @if ($d->last_check)
                        <p class="mt-2 text-xs text-amber-400">Last check: {{ $d->last_check }}</p>
                    @endif
                @endunless
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-neutral-800 p-8 text-center text-sm text-neutral-500">No custom domains yet.</li>
        @endforelse
    </ul>
@endif
@endsection
