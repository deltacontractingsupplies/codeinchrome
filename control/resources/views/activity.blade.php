@extends('layout')
@section('title', 'Activity')
@section('content')
<a href="{{ route('account') }}" class="text-sm text-neutral-500 hover:text-neutral-300">← Account</a>
<h1 class="mt-3 text-2xl font-semibold text-white">Activity</h1>
<p class="mt-1 text-sm text-neutral-400">Everything done to your account and sites - by you, or by an agent working in your browser. Kept for 400 days; it cannot be edited.</p>
<table class="mt-6 w-full text-left text-sm">
    <thead class="text-neutral-500"><tr><th class="py-1 font-normal">When (UTC)</th><th class="font-normal">What</th><th class="font-normal">Site</th><th class="font-normal">Detail</th><th class="font-normal">From</th></tr></thead>
    <tbody>
    @forelse ($events as $e)
        <tr class="border-t border-neutral-800 align-top" data-action="{{ $e->action }}">
            <td class="py-1.5 pr-4 whitespace-nowrap text-neutral-400">{{ $e->created_at->format('Y-m-d H:i:s') }}</td>
            <td class="pr-4 font-mono text-xs text-neutral-200">{{ $e->action }}</td>
            <td class="pr-4 text-neutral-300">{{ $e->site }}</td>
            <td class="pr-4 font-mono text-xs text-neutral-400 break-all">{{ $e->detail ? json_encode($e->detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '' }}</td>
            <td class="text-xs text-neutral-500">{{ $e->ip ?? 'system' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="py-6 text-neutral-500">Nothing yet.</td></tr>
    @endforelse
    </tbody>
</table>
@endsection
