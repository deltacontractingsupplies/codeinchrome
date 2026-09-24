@extends('legal.layout')
@section('title', 'Terms of Service — codeinchrome')
@section('heading', 'Terms of Service')
@php($op = config('legal.operator') ?: 'codeinchrome')
@php($mail = config('legal.support_email'))
@section('body')
<p>
    These terms apply when you use codeinchrome ("the service"), operated by {{ $op }}
    ("we"). By creating an account you agree to them.
</p>

<h2>The service</h2>
<p>
    codeinchrome hosts Laravel applications. Each site runs in its own isolated container
    with its own database, disk and resource limits set by your plan, and is served over
    HTTPS. You can edit a site in the browser, and an AI agent you control can edit it
    through the same interface.
</p>

<h2>Your account</h2>
<ul>
    <li>You must give a working email address and confirm it before creating sites.</li>
    <li>You are responsible for everything done through your account, including by any AI
        agent you let act for you. Keep your password private; we recommend two-factor sign-in.</li>
    <li>An AI agent - Claude in Chrome, for example - is not part of our service. It comes from its own
        provider (Anthropic, for Claude in Chrome), under that provider's terms, and is paid for
        separately; our plans cover the hosting and the editor it works in.</li>
    <li>One person or organisation per account. You must be old enough to enter a contract where you live.</li>
</ul>

<h2>Your code and data</h2>
<p>
    Everything you put on a site - code, files, database content - stays yours. We use it only
    to run the service for you. It is standard Laravel and MySQL, and you can download it and
    move it elsewhere at any time. When you delete a site, its container, disk and database
    are removed at once; its backups are deleted within 30 days.
</p>

<h2>Acceptable use</h2>
<p>You may not use the service to:</p>
<ul>
    <li>break the law, or host content that infringes someone else's rights;</li>
    <li>send spam or bulk unsolicited mail (outgoing mail ports are closed to sites);</li>
    <li>mine cryptocurrency, run proxies or VPN exits, or attack, scan or flood any system;</li>
    <li>host malware, phishing pages or anything designed to deceive visitors;</li>
    <li>try to reach another customer's site, data or network, or to get around your plan's limits.</li>
</ul>
<p>
    We may suspend a site that breaks these rules or endangers other customers, without notice
    where the harm is ongoing, and will tell you why at your account's email address.
</p>

<h2>Plans, payment and cancellation</h2>
<p>
    Paid plans are monthly subscriptions. Payments are handled by our reseller, Lemon Squeezy,
    which is the merchant of record for your order and handles the charge, invoices and sales
    tax. You can cancel at any time from the Billing page; your plan stays active until the end
    of the period you have paid for. Refunds follow our <a href="{{ route('refunds') }}">refund policy</a>.
</p>
<p>
    New accounts get a free trial of {{ config('billing.trial.days') }} days, with no card. When a
    trial ends without a paid plan, the account's sites are paused: they are not served and cannot be
    edited, but their files and databases are kept, and you can download each database. They are
    deleted {{ config('billing.trial.grace_days') }} days later unless you subscribe before then, in
    which case they come back as they were. If a paid plan ends, the same happens with
    {{ config('billing.trial.lapsed_grace_days') }} days before deletion. We email you before a trial
    ends, when sites are paused (with the date they will be deleted), and when they are deleted.
</p>
@unless (\App\Billing\Sales::open())
<p>
    Paid plans are not on sale yet. Until they are, trials do not run out and free sites keep running.
    When paid plans open we email every free account, and its {{ config('billing.trial.days') }}-day trial
    starts only then.
</p>
@endunless
<p>
    A plan's storage covers its sites' files and databases together. While an account is over it,
    new sites and uploads are refused; its sites keep running.
</p>

<h2>Availability and backups</h2>
<p>
    We monitor every host and site each minute and back up every site nightly. We work to keep
    the service running, but we do not promise it will never be interrupted, and backups are a
    safety net rather than a replacement for keeping your own copy of your code.
</p>

<h2>Liability</h2>
<p>
    The service is provided as is. To the extent the law allows, we are not liable for indirect
    or consequential loss, lost profits or lost data, and our total liability for any claim is
    limited to the amount you paid us in the three months before it arose. Nothing in these terms
    limits liability that cannot be limited by law.
</p>

<h2>Ending the agreement</h2>
<p>
    You can delete your account at any time from the Account page; that removes every site first.
    We may close an account that seriously or repeatedly breaks these terms.
</p>

<h2>Changes</h2>
<p>
    If we change these terms in a way that matters, we will email you at least 30 days before
    the change takes effect.
</p>

@if (config('legal.jurisdiction'))
<h2>Governing law</h2>
<p>These terms are governed by the law of {{ config('legal.jurisdiction') }}.</p>
@endif

<h2>Contact</h2>
<p><a href="mailto:{{ $mail }}">{{ $mail }}</a></p>
@endsection
