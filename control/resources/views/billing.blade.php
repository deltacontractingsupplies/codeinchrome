@extends('layout')
@section('title', 'Billing')
@section('content')
<h1 class="text-2xl font-semibold text-white">Billing</h1>
<p class="mt-1 text-sm text-neutral-400">
    You are on the <strong class="text-neutral-200">{{ $plans[$current]['name'] ?? $current }}</strong> plan.
    @if ($subscription)
        Subscription {{ str_replace('_', ' ', $subscription->status) }}@if ($subscription->renews_at && $subscription->status === 'active'), renews {{ $subscription->renews_at->toFormattedDateString() }}@endif
        @if ($subscription->ends_at), ends {{ $subscription->ends_at->toFormattedDateString() }}@endif.
    @endif
</p>
@if ($subscription?->portal_url)
    <a href="{{ $subscription->portal_url }}" rel="noopener" class="mt-4 inline-block rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-200 hover:border-neutral-500">
        Manage billing, card and cancellation
    </a>
@endif

@include('partials.trial')

<div class="mt-8 grid max-w-4xl gap-4 md:grid-cols-2">
    @foreach ($plans as $key => $plan)
        <div class="rounded-lg border p-5 {{ $key === $current ? 'border-teal-600' : 'border-neutral-800' }}">
            <div class="flex items-start justify-between gap-3">
                <div class="font-medium text-neutral-100">{{ $plan['name'] }}</div>
                @if ($plan['price'] > 0 && $key !== $current)
                    @include('partials.stock', ['left' => $stock[$key] ?? null])
                @endif
            </div>
            <div class="mt-1 text-2xl font-semibold text-white">
                @if ($plan['price'] > 0)
                    ${{ $plan['price'] }}<span class="text-sm font-normal text-neutral-500">/month</span>
                @else
                    $0<span class="text-sm font-normal text-neutral-500">{{ \App\Billing\Sales::open() ? ' for '.config('billing.trial.days').' days' : ' - free for now; paid plans open soon' }}</span>
                @endif
            </div>
            <ul class="mt-3 space-y-1 text-sm text-neutral-400">
                <li>{{ $plan['sites'] }} {{ Str::plural('site', $plan['sites']) }}</li>
                <li>{{ $plan['sites'] > 1 ? $plan['disk_gb'].' GB of storage for each site - '.$plan['storage_gb'].' GB in all' : $plan['storage_gb'].' GB of storage for your site' }} (files and databases)</li>
                @if ($cap = app(\App\Billing\Capacity::class)->forPlan($key))
                    <li>{{ $plan['sites'] > 1 ? 'Each site' : 'Your site' }}: up to ~{{ number_format($cap['concurrent_visitors']) }} visitors at once</li>
                @endif
                <li>{{ $plan['custom_domains'] ? 'Your own domains' : 'A free .codeinchrome.com address' }}</li>
            </ul>
            <div class="mt-4">
                @if ($key === $current)
                    <span class="text-sm text-teal-400">Current plan</span>
                @elseif (! $plan['price'])
                    <span class="text-sm text-neutral-500">{{ auth()->user()->trial_ends_at ? 'Trial used' : 'Free' }}</span>
                @elseif (! $plan['variant_id'])
                    <span class="text-sm text-neutral-500">Not available to buy yet</span>
                @elseif (($stock[$key] ?? 0) < 1)
                    {{-- Before the portal link too: a plan change there would
                         otherwise bypass the stock check. --}}
                    <span class="text-sm font-medium text-amber-300">Out of stock</span>
                @elseif ($subscription?->portal_url && $subscription->entitled())
                    {{-- An existing subscriber changes plan in the portal, so they
                         are never charged for two subscriptions at once. --}}
                    <a href="{{ $subscription->portal_url }}" rel="noopener" class="text-sm text-teal-400">Change in billing portal</a>
                @else
                    <form method="POST" action="{{ route('billing.checkout') }}">@csrf
                        <input type="hidden" name="plan" value="{{ $key }}">
                        <button class="w-full rounded-md bg-teal-500 px-3 py-1.5 text-sm font-medium text-neutral-950 hover:bg-teal-400">Choose {{ $plan['name'] }}</button>
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>
@endsection
