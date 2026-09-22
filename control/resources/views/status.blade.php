@extends('layout')
@section('title', 'Fleet status')
@section('content')
<h1 class="text-2xl font-semibold text-white">Fleet status</h1>
<p class="mt-1 text-sm text-neutral-400">
    Checked every minute from the control host. An incident opens after two consecutive failures.
    Alerts: {{ $webhook ? 'sent to the configured webhook' : 'NO WEBHOOK CONFIGURED - set CIC_ALERT_WEBHOOK; incidents are only logged and shown here' }}.
</p>

<h2 class="mt-8 text-lg font-medium text-white">Open incidents ({{ $open->count() }})</h2>
@forelse ($open as $i)
    <div class="mt-2 rounded-md border border-red-900 bg-red-950/40 p-3 text-sm">
        <strong class="text-red-300">{{ $i->label }}</strong> — {{ $i->detail }}
        <span class="text-neutral-500">since {{ $i->started_at->diffForHumans() }}{{ $i->alerted ? '' : ' · alert NOT delivered' }}</span>
    </div>
@empty
    <p class="mt-2 text-sm text-teal-400">None.</p>
@endforelse

<h2 class="mt-8 text-lg font-medium text-white">Checks</h2>
<table class="mt-2 w-full text-left text-sm">
    <thead class="text-neutral-500"><tr><th class="py-1 font-normal"></th><th class="font-normal">What</th><th class="font-normal">Detail</th><th class="font-normal">Latency</th><th class="font-normal">Checked</th></tr></thead>
    <tbody>
    @foreach ($monitors as $m)
        <tr class="border-t border-neutral-800">
            <td class="py-1.5 pr-2"><span class="inline-block h-2 w-2 rounded-full {{ $m->fail_streak === 0 ? 'bg-teal-400' : ($m->up ? 'bg-amber-400' : 'bg-red-500') }}"></span></td>
            <td class="pr-4 text-neutral-200">{{ $m->label }}</td>
            <td class="pr-4 text-neutral-400">{{ $m->detail }}</td>
            <td class="pr-4 text-neutral-400">{{ $m->latency_ms !== null ? $m->latency_ms.' ms' : '' }}</td>
            <td class="text-neutral-500">{{ $m->checked_at?->diffForHumans() }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<h2 class="mt-8 text-lg font-medium text-white">Recently resolved</h2>
@forelse ($recent as $i)
    <p class="mt-1 text-sm text-neutral-400">{{ $i->label }} — {{ $i->detail }} ·
        {{ $i->started_at->format('M j H:i') }} to {{ $i->resolved_at->format('H:i') }} UTC</p>
@empty
    <p class="mt-2 text-sm text-neutral-500">None.</p>
@endforelse
@endsection
