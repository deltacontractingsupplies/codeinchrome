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
<div class="mt-10">
    <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">Start free</a>
</div>
@endsection
