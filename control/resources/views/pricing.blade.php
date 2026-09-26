@extends('layout')
@section('title', 'Pricing — codeinchrome')
@section('content')
<h1 class="text-3xl font-semibold tracking-tight text-white">Pricing</h1>
<p class="mt-3 max-w-2xl text-neutral-400" data-pricing-intro>
    @if (\App\Billing\Sales::open())
        One plan, monthly, in US dollars. Try it free for {{ config('billing.trial.days') }} days with no card; cancel any time from
        the Billing page. Sales tax is added at checkout where it applies. We sell only as many plans as our
        servers can hold at full speed, so the count below goes down as they sell.
    @else
        Free for now, with no card: paid plans open soon. Your site keeps running until then, and before any
        trial clock starts you get an email and a full {{ config('billing.trial.days') }} days to decide.
    @endif
</p>
@include('partials.plans')
<section class="mt-10 max-w-3xl text-sm text-neutral-400" aria-labelledby="storage-heading">
    <h2 id="storage-heading" class="font-medium text-neutral-100">Storage, and keeping uploads elsewhere</h2>
    <p class="mt-2">
        Your plan's storage covers your sites' files and their databases together. User uploads - images, videos,
        documents - are usually better kept in object storage, and Laravel supports it out of the box:
        install <code class="text-neutral-200">league/flysystem-aws-s3-v3</code>, put the bucket's keys in <code class="text-neutral-200">.env</code>
        and set <code class="text-neutral-200">FILESYSTEM_DISK=s3</code>. It works with Amazon S3 and with Cloudflare R2, which charges nothing for
        downloads. Files kept there do not count toward your storage. Each site's settings page has the exact steps.
    </p>
</section>
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
        One site on each plan, at that plan's limits, was put under real load on our fleet: {{ $measured['workload'] }}.
        So every figure below is <strong class="font-medium text-neutral-200">per site</strong>: each of your sites has the same limits.
        Sites share their server's processors with other sites, so if the neighbours on a server are busy at the same
        moment, a site can serve somewhat less - which is why the plans say "up to".
        Page views per second were stepped up until one step missed the bar -
        p95 under {{ $measured['bar']['p95_ms'] }} ms and under {{ $measured['bar']['errors'] * 100 }}% errors. The last step that
        passed is the plan's number.
        "Visitors at once" assumes each visitor opens a page every {{ \App\Billing\Capacity::VISITOR_SECONDS_PER_PAGE }} seconds while browsing;
        your own app may be lighter or heavier. Measured {{ \Illuminate\Support\Carbon::parse($measured['measured_at'])->toFormattedDateString() }}.
    </p>
    @if (! empty($measured['websocket_bar']))
        <p class="mt-3 max-w-3xl text-sm text-neutral-400">
            WebSockets were measured separately: {{ $measured['websocket_bar']['workload'] }}.
            Connections were stepped up until a step missed the bar - at least {{ $measured['websocket_bar']['subscribed'] * 100 }}% of them
            connected and held for a full minute, and 95% of broadcasts delivered within {{ $measured['websocket_bar']['p95_delivery_ms'] }} ms.
        </p>
    @endif
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="text-neutral-500"><tr><th class="py-2 pr-6">Plan</th><th class="py-2 pr-6">Page views/s</th><th class="py-2 pr-6">p95 at that load</th><th class="py-2 pr-6">Visitors at once</th><th class="py-2">WebSocket connections</th></tr></thead>
            <tbody class="text-neutral-300">
            @foreach (\App\Billing\Sales::plans() as $key => $plan)
                @if ($cap = app(\App\Billing\Capacity::class)->forPlan($key))
                    <tr class="border-t border-neutral-800"><td class="py-2 pr-6">{{ $plan['name'] }}</td><td class="py-2 pr-6">{{ $cap['page_views_per_second'] }}</td><td class="py-2 pr-6">{{ $cap['p95_ms'] }} ms</td><td class="py-2 pr-6">~{{ number_format($cap['concurrent_visitors']) }}</td><td class="py-2">{{ ! ($plan['background'] ?? false) ? 'not on this plan' : ($cap['websocket_connections'] ? '~'.$cap['websocket_label'] : 'not measured yet') }}</td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
    </div>
    <details class="mt-4 text-sm text-neutral-400">
        <summary class="cursor-pointer text-neutral-200">View every step of the test</summary>
        @foreach (\App\Billing\Sales::plans() as $planKey => $plan)
            @php($key = app(\App\Billing\Capacity::class)->measuredAs($planKey))
            @continue(! $key || empty($measured['plans'][$key]))
            @php($p = $measured['plans'][$key])
            <h3 class="mt-4 font-medium text-neutral-200">{{ $plan['name'] }}@if ($key !== $planKey) <span class="font-normal text-neutral-500">(measured at the same limits)</span>@endif</h3>
            <table class="mt-1 w-full text-left">
                <thead class="text-neutral-500"><tr><th class="pr-4">Asked</th><th class="pr-4">Served</th><th class="pr-4">p95</th><th class="pr-4">Errors</th><th>Dropped</th></tr></thead>
                <tbody>
                @foreach ($p['steps'] ?? [] as $st)
                    <tr><td class="pr-4">{{ $st['rate'] }}/s</td><td class="pr-4">{{ round($st['achieved_rps'], 1) }}/s</td><td class="pr-4">{{ round($st['p95_ms']) }} ms</td><td class="pr-4">{{ round($st['failed_ratio'] * 100, 1) }}%</td><td>{{ $st['dropped'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
            @if (! empty($p['websocket_steps']))
                <table class="mt-2 w-full text-left">
                    <thead class="text-neutral-500"><tr><th class="pr-4">Connections</th><th class="pr-4">Subscribed</th><th class="pr-4">Closed early</th><th class="pr-4">Broadcasts received</th><th>p95 delivery</th></tr></thead>
                    <tbody>
                    @foreach ($p['websocket_steps'] as $st)
                        <tr><td class="pr-4">{{ number_format($st['conns']) }}</td><td class="pr-4">{{ number_format($st['subscribed']) }}</td><td class="pr-4">{{ number_format($st['closed_early']) }}</td><td class="pr-4">{{ number_format($st['ticks']) }}</td><td>{{ $st['p95_ms'] !== null ? round($st['p95_ms']).' ms' : '-' }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach
    </details>
</section>
@endif
<div class="mt-10">
    <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">{{ \App\Billing\Sales::open() ? 'Try it free for '.config('billing.trial.days').' days' : 'Start free' }}</a>
    @include('partials.trial-places')
</div>
@endsection
