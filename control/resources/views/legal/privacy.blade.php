@extends('legal.layout')
@section('title', 'Privacy Policy — codeinchrome')
@section('heading', 'Privacy Policy')
@php($op = config('legal.operator') ?: 'codeinchrome')
@php($mail = config('legal.support_email'))
@section('body')
<p>
    This policy explains what {{ $op }} collects when you use codeinchrome, why, and for how long.
    We collect as little as the service needs and sell nothing.
</p>

<h2>What we collect</h2>
<ul>
    <li><strong>Your account:</strong> name, email address, and your password - stored only as a
        one-way hash. If you turn on two-factor sign-in, its secret, encrypted.</li>
    <li><strong>Your sites:</strong> the code, files and database content you put on them.</li>
    <li><strong>Activity:</strong> an audit log of actions on your account and sites (for example
        "site created", "password changed"), with time and IP address.</li>
    <li><strong>Requests to your sites:</strong> each site's web server logs requests (time, address,
        path, status) so you can read them in the editor's Logs view.</li>
    <li><strong>Billing:</strong> your plan and subscription status. Card details go to Lemon Squeezy
        and never reach us.</li>
</ul>

<h2>Why</h2>
<p>
    To run your account and sites, keep them secure, find and fix faults, and bill you. We do not
    use your data for advertising and do not sell or rent it to anyone.
</p>

<h2>How long we keep it</h2>
<ul>
    <li>Account data: until you delete your account.</li>
    <li>Site content: until you delete the site. Backups: nightly, kept for up to 3 months while
        the site exists, and deleted within 30 days after the site is deleted.</li>
    <li>Audit log: 400 days.</li>
    <li>Site request logs: up to 90 days.</li>
</ul>

<h2>Who else handles it</h2>
<ul>
    <li><strong>Hetzner Online</strong> - our servers, in the EU (Finland).</li>
    <li><strong>Cloudflare</strong> - DNS, and the network in front of this service and of every site on it:
        all web traffic passes through it (including visitors' IP addresses and the pages and requests
        themselves), and it filters attacks.</li>
    <li><strong>Lemon Squeezy</strong> - payments, as merchant of record. Its own privacy policy
        covers what you give it at checkout.</li>
    <li><strong>Have I Been Pwned</strong> - when you choose a password we check it against known
        breaches using only the first five characters of its hash; the password itself is never sent.</li>
</ul>
<p>Email is sent from our own mail server; no email provider sees it.</p>

<h2>AI agents</h2>
<p>
    We do not send your code, your sites' data or anything else to an AI provider. If you use an AI
    agent to work in the editor - Claude in Chrome, for example - it is a separate service you choose
    and pay its provider for (Anthropic, for Claude in Chrome), and what it reads and does in your
    browser is handled under that provider's own terms and privacy policy, not ours.
</p>

<h2>Cookies</h2>
<p>
    One session cookie to keep you signed in, and one security cookie that protects forms against
    forgery. No analytics, no tracking, no third-party cookies.
</p>

<h2>Your sites' visitors</h2>
<p>
    For the personal data your own application collects from its visitors, you are the controller
    and we process it on your behalf, only to host your site.
</p>

<h2>Your rights</h2>
<p>
    You can see and change your details on the Account page, download your sites' code and data at
    any time, and delete your account, which removes everything above except where the law requires
    us to keep billing records. For anything else - access, correction, a question or a complaint -
    write to <a href="mailto:{{ $mail }}">{{ $mail }}</a>. You may also complain to your data
    protection authority.
</p>
@endsection
