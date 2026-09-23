@extends('layout')
@section('title', $site->site_id.' backups')
@if ($operation && ($operation['state'] ?? '') === 'running')
    {{-- No script needed to follow progress: the page reloads itself. --}}
    @push('head')<meta http-equiv="refresh" content="10">@endpush
@endif
@section('content')
<p class="text-sm"><a href="{{ route('dashboard') }}" class="text-neutral-500 hover:text-neutral-300">Sites</a> <span class="text-neutral-600">/</span> {{ $site->domain }}</p>
<h1 class="mt-2 text-2xl font-semibold text-white">Backups</h1>
<p class="mt-2 max-w-2xl text-sm text-neutral-400">
    Your site's files and database are backed up every night, encrypted, to a separate server.
    Restoring one replaces both. Before a restore starts, the site is backed up as it is,
    so you can always go back to where you were.
</p>

@if ($operation)
    @php($state = $operation['state'] ?? '')
    <div class="mt-6 max-w-2xl rounded-md border px-4 py-3 text-sm {{ $state === 'failed' ? 'border-red-900 bg-red-950/50 text-red-200' : ($state === 'running' ? 'border-amber-800 bg-amber-950/40 text-amber-200' : 'border-teal-800 bg-teal-950/50 text-teal-200') }}" role="status">
        <p class="font-medium">
            @if ($state === 'running') {{ $operation['kind'] === 'restore' ? 'Restoring' : 'Backing up' }} - started {{ \Illuminate\Support\Carbon::parse($operation['started'])->diffForHumans() }}
            @elseif ($state === 'failed') The last {{ $operation['kind'] }} did not finish
            @else The last {{ $operation['kind'] }} finished {{ isset($operation['finished']) ? \Illuminate\Support\Carbon::parse($operation['finished'])->diffForHumans() : '' }}
            @endif
        </p>
        @if (! empty($operation['message']))<p class="mt-1">{{ $operation['message'] }}</p>@endif
    </div>
@endif

@if ($errors->any())
    <p class="mt-6 max-w-2xl rounded-md border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ $errors->first() }}</p>
@endif

@if ($error)
    <p class="mt-6 max-w-2xl rounded-md border border-red-900 bg-red-950/50 px-4 py-3 text-sm text-red-200">{{ $error }}</p>
@endif

@if ($site->status === 'live')
    <form method="POST" action="{{ route('sites.backups.store', $site) }}" class="mt-6">@csrf
        <button class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400" @disabled(($operation['state'] ?? '') === 'running')>Back up now</button>
    </form>
@endif

@if (! $error && $site->status === 'live')
    @if ($backups === [])
        <p class="mt-8 text-sm text-neutral-400">No backups yet. The first nightly backup runs at 02:00 UTC, or use Back up now.</p>
    @else
        <table class="mt-8 w-full max-w-3xl text-left text-sm">
            <thead class="text-neutral-500">
                <tr class="border-b border-neutral-800">
                    <th scope="col" class="py-2 pr-6 font-normal">Taken</th>
                    <th scope="col" class="py-2 pr-6 font-normal">Backup</th>
                    <th scope="col" class="py-2 font-normal"><span class="sr-only">Restore</span></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($backups as $b)
                <tr class="border-b border-neutral-800 align-top">
                    <td class="py-3 pr-6 text-neutral-200">
                        {{ \Illuminate\Support\Carbon::parse($b['time'])->utc()->format('j M Y, H:i') }} UTC
                        <span class="block text-xs text-neutral-500">{{ \Illuminate\Support\Carbon::parse($b['time'])->diffForHumans() }}</span>
                    </td>
                    <td class="py-3 pr-6 font-mono text-neutral-400">{{ $b['id'] }}</td>
                    <td class="py-3">
                        @if ($b['hasDatabase'])
                            <details>
                                <summary class="cursor-pointer text-teal-300">Restore this backup</summary>
                                <form method="POST" action="{{ route('sites.backups.restore', $site) }}" class="mt-3 space-y-3">@csrf
                                    <input type="hidden" name="snapshot" value="{{ $b['id'] }}">
                                    <label class="flex gap-2 text-neutral-300">
                                        <input type="checkbox" name="confirm" value="1" class="mt-1" required>
                                        <span>Replace the site's files and database with this backup. The site is backed up first and is offline for a few minutes.</span>
                                    </label>
                                    <button class="rounded-md border border-red-800 px-3 py-1.5 text-red-300 hover:border-red-500" @disabled(($operation['state'] ?? '') === 'running')>Restore</button>
                                </form>
                            </details>
                        @else
                            <span class="text-neutral-500" title="The database part of this backup is missing">Files only - cannot be restored as a whole</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endif
@endsection
