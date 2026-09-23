@extends('layout')
@section('title', 'codeinchrome — Laravel hosting the AI can drive')
@section('content')
@php($capacity = app(\App\Billing\Capacity::class))
<div class="pt-6 sm:pt-12">
    <h1 class="max-w-3xl text-4xl font-semibold tracking-tight text-white sm:text-6xl sm:leading-[1.05]">
        Tell the AI what to build. Watch it write your Laravel app, live.
    </h1>
    <p class="mt-6 max-w-2xl text-lg leading-relaxed text-neutral-400">
        Every site is a real Laravel app on its own server container, on HTTPS from the first second.
        An AI agent works in the editor beside you: it reads your files, writes code, runs artisan and
        shows you the result. Every change is saved as a version, so nothing it does is permanent until you say so.
    </p>
    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">Start free</a>
        <a href="#plans" class="rounded-md border border-neutral-700 px-5 py-2.5 text-neutral-200 hover:border-neutral-500">See plans</a>
    </div>
</div>

{{-- The editor, drawn in HTML rather than a screenshot so it stays sharp,
     follows the theme and reads to a screen reader as what it is. --}}
<figure class="mt-14 overflow-hidden rounded-xl border border-neutral-800 bg-neutral-900 shadow-xl shadow-black/25" aria-labelledby="editor-caption">
    <div class="flex h-10 items-center gap-3 border-b border-neutral-800 px-4 text-xs text-neutral-500">
        <span class="font-medium text-neutral-300">shop.codeinchrome.com</span>
        <span class="hidden sm:inline">Files</span>
        <span class="hidden sm:inline">History</span>
        <span class="hidden sm:inline">Database</span>
        <span class="ml-auto text-teal-400">Agent connected</span>
    </div>
    <div class="grid text-[13px] leading-6 md:grid-cols-[12.5rem_1fr_19rem]">
        <ul class="hidden min-w-0 truncate border-r border-neutral-800 py-3 font-mono text-neutral-400 md:block" aria-label="Files">
            <li class="px-4">app/</li>
            <li class="px-4">database/</li>
            <li class="px-4">resources/</li>
            <li class="pl-7">views/</li>
            <li class="pl-10">layouts/</li>
            <li class="pl-10">shop/</li>
            <li class="bg-neutral-800 pl-12 text-neutral-100">index.blade.php</li>
            <li class="pl-12">product.blade.php</li>
            <li class="px-4">routes/</li>
            <li class="pl-7">web.php</li>
            <li class="px-4">composer.json</li>
        </ul>

        <div class="min-w-0 border-neutral-800 bg-neutral-950 md:border-r">
            <div class="flex border-b border-neutral-800 text-xs">
                <span class="border-r border-neutral-800 bg-neutral-950 px-4 py-2 text-neutral-200">shop/index.blade.php</span>
                <span class="px-4 py-2 text-neutral-500">web.php</span>
            </div>
            <pre class="overflow-x-auto bg-neutral-950 px-4 py-3 font-mono text-neutral-300" aria-label="Code the agent wrote">@verbatim<span class="text-neutral-600">1</span>  <span class="text-teal-300">&lt;x-layouts.shop</span> <span class="text-neutral-400">title=</span><span class="text-amber-300">"New in"</span><span class="text-teal-300">&gt;</span>
<span class="text-neutral-600">2</span>    <span class="text-teal-300">&lt;section</span> <span class="text-neutral-400">class=</span><span class="text-amber-300">"grid gap-6 sm:grid-cols-3"</span><span class="text-teal-300">&gt;</span>
<span class="text-neutral-600">3</span>      @foreach ($products as $product)
<span class="text-neutral-600">4</span>        <span class="text-teal-300">&lt;x-product-card</span> <span class="text-neutral-400">:product=</span><span class="text-amber-300">"$product"</span> <span class="text-teal-300">/&gt;</span>
<span class="text-neutral-600">5</span>      @endforeach
<span class="text-neutral-600">6</span>    <span class="text-teal-300">&lt;/section&gt;</span>
<span class="text-neutral-600">7</span>    {{ $products->links() }}
<span class="text-neutral-600">8</span>  <span class="text-teal-300">&lt;/x-layouts.shop&gt;</span>@endverbatim</pre>
        </div>

        <ol class="space-y-3 border-t border-neutral-800 p-4 text-neutral-300 md:border-t-0" aria-label="The agent's steps">
            <li class="rounded-md bg-neutral-800 px-3 py-2 text-neutral-100">Show the newest products on the shop page, three per row.</li>
            <li><code class="font-mono break-all text-teal-300">cic.read('routes/web.php')</code></li>
            <li><code class="font-mono break-all text-teal-300">cic.write('resources/views/shop/index.blade.php')</code>
                <span class="block text-xs text-neutral-500">Saved as version <span class="text-amber-300">3f2a1c9</span></span></li>
            <li><code class="font-mono break-all text-teal-300">cic.run('php artisan view:cache')</code>
                <span class="block text-xs text-neutral-500">Done in 0.4 s</span></li>
            <li class="text-neutral-400">The shop page now lists 12 products per page. Open it to check, or restore the previous version from History.</li>
        </ol>
    </div>
    <figcaption id="editor-caption" class="border-t border-neutral-800 px-4 py-3 text-sm text-neutral-500">
        The editor in the browser. You type what you want; the agent does it with the same file, terminal and database tools you have.
    </figcaption>
</figure>

<section class="mt-24 grid gap-x-12 gap-y-10 sm:grid-cols-2" aria-labelledby="what-heading">
    <h2 id="what-heading" class="text-2xl font-semibold text-white sm:col-span-2">What you get with every site</h2>
    @foreach ([
        ['A real Laravel app', 'PHP 8.3, MySQL, Composer and artisan, on a subdomain or your own domain. Standard code you can clone and take anywhere; nothing here is proprietary.'],
        ['An editor that is also a file manager', 'Edit, upload, download, zip and unzip, search every file, and delete to a bin you can restore from. The agent uses the same tools.'],
        ['Every change kept', 'Each save, upload and command is recorded as a git version. See what changed and when, open any old version, and restore it with one click.'],
        ['Queues, schedules and WebSockets', 'Switch on a queue worker, the scheduler or Laravel Reverb per site. They run beside your app and restart if they stop.'],
        ['Only public/ is on the internet', 'The web server cannot serve anything above public/, so .env, storage and vendor are unreachable by construction, not by a rule someone could forget.'],
        ['Isolated from everyone else', 'Each site is its own container with its own network, its own database user and capped memory and CPU. We test that one site cannot reach another on every server.'],
    ] as [$title, $body])
        <div class="border-t border-neutral-800 pt-5">
            <h3 class="font-medium text-neutral-100">{{ $title }}</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">{{ $body }}</p>
        </div>
    @endforeach
</section>

@if (config('showcase.url'))
<section class="mt-24" aria-labelledby="showcase-heading">
    <h2 id="showcase-heading" class="text-2xl font-semibold text-white">A store the agent built</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">
        A coffee shop with products, a cart, Stripe checkout and an admin panel, written into a new site by the agent through this editor.
        @if (config('showcase.checkout'))
            Place an order with Stripe's test card 4242 4242 4242 4242 - nothing is charged - then find it in the admin panel.
        @else
            Browse it, fill a bag, and look around the admin panel.
        @endif
    </p>
    <div class="mt-6 grid gap-8 lg:grid-cols-[1fr_20rem]">
        @if (config('showcase.recording'))
            <img src="{{ config('showcase.recording') }}" alt="Recording of the agent building the store in the editor, step by step" class="w-full rounded-lg border border-neutral-800" loading="lazy">
        @endif
        <dl class="space-y-4 text-sm">
            <div><dt class="text-neutral-500">Store</dt><dd><a href="{{ config('showcase.url') }}" class="text-teal-300 underline">{{ parse_url(config('showcase.url'), PHP_URL_HOST) }}</a></dd></div>
            @if (config('showcase.admin_url'))
                <div><dt class="text-neutral-500">Admin panel (read-only)</dt><dd><a href="{{ config('showcase.admin_url') }}" class="text-teal-300 underline">Open the admin panel</a></dd></div>
                <div><dt class="text-neutral-500">Email</dt><dd class="font-mono text-neutral-200">{{ config('showcase.admin_email') }}</dd></div>
                <div><dt class="text-neutral-500">Password</dt><dd class="font-mono text-neutral-200">{{ config('showcase.admin_password') }}</dd></div>
            @endif
        </dl>
    </div>
</section>
@endif

@php($measured = collect(config('billing.plans'))->map(fn ($p, $k) => ['plan' => $p, 'cap' => $capacity->forPlan($k)])->filter(fn ($r) => $r['cap']))
@if ($measured->isNotEmpty())
<section class="mt-24" aria-labelledby="capacity-heading">
    <h2 id="capacity-heading" class="text-2xl font-semibold text-white">How much traffic each plan handles</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">
        Measured, not estimated: a real Laravel store on each plan, loaded from another server until responses got slow.
        A plan passes a rate only if 95% of pages load within half a second and fewer than 1% fail.
    </p>
    <div class="mt-6 overflow-x-auto">
        <table class="w-full max-w-3xl text-left text-sm">
            <thead class="text-neutral-500">
                <tr class="border-b border-neutral-800">
                    <th scope="col" class="py-2 pr-6 font-normal">Plan</th>
                    <th scope="col" class="py-2 pr-6 font-normal">Visitors at once</th>
                    <th scope="col" class="py-2 pr-6 font-normal">Page views a second</th>
                    <th scope="col" class="py-2 pr-6 font-normal">95% of pages within</th>
                    @if ($measured->contains(fn ($r) => $r['cap']['websocket_connections']))
                        <th scope="col" class="py-2 font-normal">Live WebSocket connections</th>
                    @endif
                </tr>
            </thead>
            <tbody class="text-neutral-200">
                @foreach ($measured as $row)
                    <tr class="border-b border-neutral-800">
                        <th scope="row" class="py-2 pr-6 font-medium">{{ $row['plan']['name'] }}</th>
                        <td class="py-2 pr-6">~{{ number_format($row['cap']['concurrent_visitors']) }}</td>
                        <td class="py-2 pr-6">{{ $row['cap']['page_views_per_second'] }}</td>
                        <td class="py-2 pr-6">{{ $row['cap']['p95_ms'] ? $row['cap']['p95_ms'].' ms' : '' }}</td>
                        @if ($measured->contains(fn ($r) => $r['cap']['websocket_connections']))
                            <td class="py-2">{{ $row['cap']['websocket_connections'] ? '~'.$row['cap']['websocket_label'] : '' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-neutral-500"><a href="{{ route('pricing') }}#capacity" class="underline">How we measured</a></p>
</section>
@endif

<section class="mt-24" aria-labelledby="plans">
    <h2 id="plans" class="text-2xl font-semibold text-white">Plans</h2>
    @include('partials.plans')
    <p class="mt-4 text-sm text-neutral-500">Monthly, in US dollars, cancel any time. <a href="{{ route('pricing') }}" class="underline">Pricing details</a>.</p>
</section>
@endsection
