@extends('layout')
@section('title', 'Pricing — codeinchrome')
@section('content')
<h1 class="text-3xl font-semibold tracking-tight text-white">Pricing</h1>
<p class="mt-3 max-w-2xl text-neutral-400">
    Monthly plans, in US dollars. Cancel any time from the Billing page. Sales tax is added at
    checkout where it applies.
</p>
@include('partials.plans')
<div class="mt-10 grid gap-6 sm:grid-cols-3 text-sm text-neutral-400">
    <div>
        <div class="font-medium text-neutral-100">Every plan includes</div>
        <p class="mt-2">HTTPS, an isolated container, a private MySQL database, nightly backups, the
            in-browser editor, and per-minute monitoring.</p>
    </div>
    <div>
        <div class="font-medium text-neutral-100">Refunds</div>
        <p class="mt-2">Your first payment for a plan is refundable in full for {{ config('legal.refund_days') }} days.
            <a href="{{ route('refunds') }}" class="underline">Refund policy</a>.</p>
    </div>
    <div>
        <div class="font-medium text-neutral-100">Payments</div>
        <p class="mt-2">Handled by Lemon Squeezy, our merchant of record. We never see your card.</p>
    </div>
</div>
@php($measured = app(\App\Billing\Capacity::class)->all())
@if (! empty($measured['plans']))
<section id="capacity" class="mt-14">
    <h2 class="text-xl font-semibold text-white">How we measured</h2>
    <p class="mt-2 max-w-3xl text-sm text-neutral-400">
        Each plan's container was put under real load on our fleet: {{ $measured['workload'] }}.
        Page views per second were stepped up until one step missed the bar -
        p95 under {{ $measured['bar']['p95_ms'] }} ms and under {{ $measured['bar']['errors'] * 100 }}% errors. The last step that
        passed is the plan's number.
        "Visitors at once" assumes each visitor opens a page every {{ \App\Billing\Capacity::VISITOR_SECONDS_PER_PAGE }} seconds while browsing;
        your own app may be lighter or heavier. Measured {{ \Illuminate\Support\Carbon::parse($measured['measured_at'])->toFormattedDateString() }}.
    </p>
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-neutral-500"><tr><th class="py-2 pr-6">Plan</th><th class="py-2 pr-6">Page views/s</th><th class="py-2 pr-6">p95 at that load</th><th class="py-2">Visitors at once</th></tr></thead>
            <tbody class="text-neutral-300">
            @foreach (config('billing.plans') as $key => $plan)
                @if ($cap = app(\App\Billing\Capacity::class)->forPlan($key))
                    <tr class="border-t border-neutral-800"><td class="py-2 pr-6">{{ $plan['name'] }}</td><td class="py-2 pr-6">{{ $cap['page_views_per_second'] }}</td><td class="py-2 pr-6">{{ $cap['p95_ms'] }} ms</td><td class="py-2">~{{ number_format($cap['concurrent_visitors']) }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
    <details class="mt-4 text-sm text-neutral-400">
        <summary class="cursor-pointer text-neutral-200">View every step of the test</summary>
        @foreach ($measured['plans'] as $key => $p)
            <h3 class="mt-4 font-medium text-neutral-200">{{ config("billing.plans.$key.name", $key) }}</h3>
            <table class="mt-1 w-full text-left">
                <thead class="text-neutral-500"><tr><th class="pr-4">Asked</th><th class="pr-4">Served</th><th class="pr-4">p95</th><th class="pr-4">Errors</th><th>Dropped</th></tr></thead>
                <tbody>
                @foreach ($p['steps'] as $st)
                    <tr><td class="pr-4">{{ $st['rate'] }}/s</td><td class="pr-4">{{ round($st['achieved_rps'], 1) }}/s</td><td class="pr-4">{{ round($st['p95_ms']) }} ms</td><td class="pr-4">{{ round($st['failed_ratio'] * 100, 1) }}%</td><td>{{ $st['dropped'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endforeach
    </details>
</section>
@endif
<div class="mt-10">
    <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">Start free</a>
</div>
@endsection
