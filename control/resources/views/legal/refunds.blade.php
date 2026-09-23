@extends('legal.layout')
@section('title', 'Refund Policy — codeinchrome')
@section('heading', 'Refund Policy')
@php($days = config('legal.refund_days'))
@php($mail = config('legal.support_email'))
@section('body')
<p>
    Start on the Free plan and upgrade only when it works for you. If a paid plan still turns out
    not to be right, these are the rules.
</p>

<h2>First payment</h2>
<p>
    If you ask within {{ $days }} days of the first payment for a plan, we refund that payment in
    full - no questions asked.
</p>

<h2>Later payments</h2>
<p>
    You can cancel at any time from the Billing page and will not be charged again. The plan stays
    active until the end of the month already paid for; we do not refund part-months. If you were
    charged after cancelling, or charged twice, we refund the extra charge in full.
</p>

<h2>Suspended accounts</h2>
<p>
    No refund is due for a period in which an account was suspended for breaking the
    <a href="{{ route('terms') }}">terms of service</a>.
</p>

<h2>How to ask</h2>
<p>
    Write to <a href="mailto:{{ $mail }}">{{ $mail }}</a> from your account's email address, or
    reply to your Lemon Squeezy receipt. Refunds go back to the original payment method through
    Lemon Squeezy, our merchant of record, and usually appear within 5-10 business days.
</p>
@endsection
