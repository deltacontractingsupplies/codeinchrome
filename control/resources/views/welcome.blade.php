@extends('layout')
@section('title', 'codeinchrome — Laravel hosting the AI can drive')
@section('content')
@php($capacity = app(\App\Billing\Capacity::class))
<div class="pt-6 sm:pt-12">
    {{-- "Works with", not "powered by": the extension is Anthropic's; this platform is not theirs. --}}
    <a href="{{ config('agent.extension.page') }}" rel="noopener noreferrer" target="_blank"
       class="mb-5 inline-flex items-center gap-2 rounded-full border border-teal-700/60 bg-teal-950/40 px-3 py-1 text-sm text-teal-200 hover:border-teal-500" data-works-with="claude-in-chrome">
        <span aria-hidden="true">&#9679;</span> Works with Claude in Chrome <span aria-hidden="true">&nearr;</span>
    </a>
    <h1 class="max-w-3xl text-4xl font-semibold tracking-tight text-white sm:text-6xl sm:leading-[1.05]">
        Tell the AI what to build. Watch it write your Laravel app, live.
    </h1>
    <p class="mt-6 max-w-2xl text-lg leading-relaxed text-neutral-400">
        Every site is a real Laravel app on its own server container, on HTTPS from the first second.
        An AI agent - <a href="{{ config('agent.extension.page') }}" rel="noopener noreferrer" target="_blank" class="text-teal-300 underline">Claude in Chrome</a>, the browser extension, or any agent that can drive a browser - works
        in the editor beside you: it reads your files, writes code, runs artisan and shows you the result.
        Nothing to set up on your computer: no PHP, no database, no terminal. Every change is saved as a version, so nothing it does is permanent until you say so.
    </p>
    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">{{ \App\Billing\Sales::open() ? 'Try it free for '.config('billing.trial.days').' days' : 'Start free' }}</a>
        <a href="#get-started" class="rounded-md border border-neutral-700 px-5 py-2.5 text-neutral-200 hover:border-neutral-500">How to start</a>
        <a href="#plans" class="rounded-md border border-neutral-700 px-5 py-2.5 text-neutral-200 hover:border-neutral-500">See plans</a>
    </div>
    @include('partials.trial-places')
</div>

{{-- The editor itself, read-only, showing a demo's code as it is on the live
     site right now (App\Showcase\DemoSource): the same explorer, icons and
     colours as the real one, so it changes when the demo does. --}}
@php($preview = collect(array_keys(config('showcase.demos')))->map(fn ($key) => app(\App\Showcase\DemoSource::class)->preview($key))->filter()->first())
@if ($preview)
    <figure class="mt-14" aria-labelledby="editor-caption">
        <x-code-window class="h-[32rem]" :demo-key="$preview['key']" :domain="$preview['domain']" :tree="$preview['tree']"
            :path="$preview['path']" :file="$preview['file']" :count="$preview['count']" />
        <figcaption id="editor-caption" class="mt-3 text-sm text-neutral-500">
            The editor, showing <a href="{{ $preview['demo']['url'] }}" rel="noopener" class="underline">{{ $preview['demo']['name'] }}</a>
            as its code is right now: an AI agent wrote all of it, working in this editor.
            <a href="{{ route('demos.code', $preview['key']) }}" class="text-teal-300 underline">Read every file</a>.
        </figcaption>
    </figure>
    @vite('resources/js/demo-code.js')
@endif

{{-- Getting started with the agent: every fact from config/agent.php, read from
     Anthropic's own pages (and dated). The agent is Anthropic's, sold by them. --}}
@php($agent = config('agent'))
<section id="get-started" class="mt-24" aria-labelledby="start-heading">
    <h2 id="start-heading" class="text-2xl font-semibold text-white">Get started with {{ $agent['extension']['name'] }}</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">Five steps, about ten minutes. You need {{ $agent['extension']['browser'] }}.</p>

    <ol class="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
        <li class="rounded-lg border border-neutral-800 p-5">
            <p class="text-sm text-teal-300">Step 1</p>
            <h3 class="mt-1 font-medium text-neutral-100">Have a Claude plan that includes it</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                {{ $agent['extension']['name'] }} comes with Claude's paid plans - Pro, Max, Team or Enterprise - not with the free one.
                You buy it from Anthropic, not from us.
            </p>
            <a href="{{ $agent['plans_url'] }}" rel="noopener noreferrer" target="_blank" class="mt-3 inline-block text-sm text-teal-300 underline">Claude's plans &nearr;</a>
        </li>
        <li class="rounded-lg border border-neutral-800 p-5">
            <p class="text-sm text-teal-300">Step 2</p>
            <h3 class="mt-1 font-medium text-neutral-100">Add the extension to Chrome</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">Open its page in the Chrome Web Store and click "Add to Chrome".</p>
            <a href="{{ $agent['extension']['install'] }}" rel="noopener noreferrer" target="_blank"
               class="mt-3 inline-block rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400" data-install="claude-in-chrome">Add {{ $agent['extension']['name'] }} &nearr;</a>
        </li>
        <li class="rounded-lg border border-neutral-800 p-5">
            <p class="text-sm text-teal-300">Step 3</p>
            <h3 class="mt-1 font-medium text-neutral-100">Sign in and pin it</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                Sign in with your Claude account when it asks. Pin it: click the puzzle-piece icon in Chrome's toolbar, then the pin next to Claude.
                Allow the permissions it asks for, so it can work in a page.
            </p>
            <a href="{{ $agent['extension']['help'] }}" rel="noopener noreferrer" target="_blank" class="mt-3 inline-block text-sm text-teal-300 underline">Anthropic's setup guide &nearr;</a>
        </li>
        <li class="rounded-lg border border-neutral-800 p-5">
            <p class="text-sm text-teal-300">Step 4</p>
            <h3 class="mt-1 font-medium text-neutral-100">Create your site here</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                {{ \App\Billing\Sales::open() ? 'Start the '.config('billing.trial.days').'-day free trial' : 'Sign up free' }}, no card, and create a site: a real Laravel app, live on HTTPS in seconds.
                Then open it in the editor.
            </p>
            <a href="{{ route('register') }}" class="mt-3 inline-block text-sm text-teal-300 underline">{{ \App\Billing\Sales::open() ? 'Start the free trial' : 'Sign up free' }}</a>
        </li>
        <li class="rounded-lg border border-neutral-800 p-5 md:col-span-2 lg:col-span-2">
            <p class="text-sm text-teal-300">Step 5</p>
            <h3 class="mt-1 font-medium text-neutral-100">Open Claude beside the editor and say what you want</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                On the editor's tab, click the Claude icon to open it in Chrome's side panel. Press <strong class="text-neutral-200">Copy for agent</strong> at the top of the editor
                and paste it into Claude's chat: Claude reads how to work here and answers that it is connected - the editor shows "Agent connected".
                Then describe the site: "a booking page for my
                barbershop, with an admin to see the day's appointments". The editor tells Claude how to work in it; you watch every file it writes,
                and every change is a version you can undo. Any other AI agent that can drive a browser can work here the same way.
            </p>
        </li>
    </ol>

    <div class="mt-8 grid gap-4 rounded-lg border border-neutral-800 bg-neutral-900/40 p-5 md:grid-cols-2" data-what-you-pay>
        <div>
            <h3 class="font-medium text-neutral-100">What you pay us</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                The hosting and the editor: your sites, their databases, backups and HTTPS.
                @if (\App\Billing\Sales::open())
                    Starter is ${{ config('billing.plans.starter.price') }} a month, after a {{ config('billing.trial.days') }}-day free trial.
                @else
                    Free for now - paid plans open soon, and you get an email before anything changes.
                @endif
                It does not include an AI agent.
            </p>
        </div>
        <div>
            <h3 class="font-medium text-neutral-100">What you pay Anthropic</h3>
            <p class="mt-2 text-sm leading-relaxed text-neutral-400">
                The agent: a Claude plan with {{ $agent['extension']['name'] }}, {{ $agent['from'] }} to {{ $agent['to'] }} a month
                ({{ collect($agent['plans'])->map(fn ($p) => $p['name'].' '.$p['price'])->implode(', ') }}; Team and Enterprise too),
                billed by Anthropic, separately from us.
            </p>
            <p class="mt-2 text-xs text-neutral-500">
                Anthropic's prices as listed on <a href="{{ $agent['plans_url'] }}" rel="noopener noreferrer" target="_blank" class="underline">claude.com</a>
                on {{ \Illuminate\Support\Carbon::parse($agent['checked_on'])->toFormattedDateString() }}; theirs to change, so check there.
            </p>
        </div>
    </div>
</section>

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

{{-- The editor's built-in extensions: the same list as its Extensions view
     (resources/data/extensions.json), so the two never disagree. --}}
@php($extensionsFile = resource_path('data/extensions.json'))
@php($extensions = is_file($extensionsFile) ? (json_decode(file_get_contents($extensionsFile), true) ?: []) : [])
@if ($extensions)
<section class="mt-24" aria-labelledby="extensions-heading">
    <h2 id="extensions-heading" class="text-2xl font-semibold text-white">Inside the editor</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">
        Built from open-source projects, as VS Code is. Each one is listed in the editor's Extensions view,
        where you can switch it off.
    </p>
    <ul class="mt-8 grid gap-x-10 gap-y-6 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($extensions as $ext)
            <li class="border-t border-neutral-800 pt-4" data-extension="{{ $ext['id'] }}">
                <h3 class="font-medium text-neutral-100">{{ $ext['name'] }}</h3>
                <p class="mt-1.5 text-sm leading-relaxed text-neutral-400">{{ $ext['what'] }}</p>
                <p class="mt-1.5 text-xs text-neutral-500">
                    @if ($ext['url'])
                        <a href="{{ $ext['url'] }}" rel="noopener noreferrer" class="underline">{{ $ext['by'] }}</a>
                    @else
                        {{ $ext['by'] }}
                    @endif
                    &middot; {{ $ext['licence'] }}
                </p>
            </li>
        @endforeach
    </ul>
</section>
@endif

@if (config('showcase.demos'))
<section id="demos" class="mt-24" aria-labelledby="demos-heading">
    <h2 id="demos-heading" class="text-2xl font-semibold text-white">Stores AI agents built here</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">
        Real sites on this platform, written by AI agents working in the editor. Shop in them, sign in to their admin panels
        (read-only for these logins), and read every line of their code.
    </p>
    @if (config('showcase.recording'))
        <img src="{{ config('showcase.recording') }}" alt="Recording of an agent building a store in the editor, step by step" class="mt-6 w-full rounded-lg border border-neutral-800" loading="lazy">
    @endif
    <div class="mt-6 grid gap-4 md:grid-cols-2">
        @foreach (config('showcase.demos') as $key => $demo)
            <article class="flex flex-col rounded-lg border border-neutral-800 p-6" aria-labelledby="demo-{{ $key }}">
                <h3 id="demo-{{ $key }}" class="text-lg font-medium text-neutral-100">{{ $demo['name'] }}</h3>
                <p class="mt-2 text-sm text-neutral-400">{{ $demo['what'] }}</p>
                <p class="mt-2 text-sm text-neutral-500">{{ $demo['built'] }}</p>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex gap-2"><dt class="w-24 shrink-0 text-neutral-500">Store</dt><dd><a href="{{ $demo['url'] }}" rel="noopener" class="text-teal-300 underline">{{ parse_url($demo['url'], PHP_URL_HOST) }}</a></dd></div>
                    @if ($demo['admin_url'])
                        <div class="flex gap-2"><dt class="w-24 shrink-0 text-neutral-500">Admin</dt><dd><a href="{{ $demo['admin_url'] }}" rel="noopener" class="text-teal-300 underline">Open the admin panel</a> <span class="text-neutral-500">(read-only)</span></dd></div>
                        <div class="flex gap-2"><dt class="w-24 shrink-0 text-neutral-500">Email</dt><dd class="font-mono text-neutral-200">{{ $demo['admin_email'] }}</dd></div>
                        <div class="flex gap-2"><dt class="w-24 shrink-0 text-neutral-500">Password</dt><dd class="font-mono text-neutral-200">{{ $demo['admin_password'] }}</dd></div>
                    @endif
                </dl>
                <div class="mt-5 flex flex-wrap gap-2">
                    <a href="{{ $demo['url'] }}" rel="noopener" class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Visit the store</a>
                    <a href="{{ route('demos.code', $key) }}" class="rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-200 hover:border-neutral-500">Read the code</a>
                </div>
            </article>
        @endforeach
    </div>
</section>
@endif

{{-- What is for sale (App\Billing\Sales); WebSockets only where Reverb runs (a paid plan's background processes). --}}
@php($measured = collect(\App\Billing\Sales::plans())->map(fn ($p, $k) => ['plan' => $p, 'cap' => ($c = $capacity->forPlan($k)) && ! ($p['background'] ?? false) ? ['websocket_connections' => null, 'websocket_label' => null] + $c : $c])->filter(fn ($r) => $r['cap']))
@if ($measured->isNotEmpty())
<section id="explore" class="mt-24" aria-labelledby="explore-heading">
    <h2 id="explore-heading" class="text-2xl font-semibold text-white">Sites people are building here</h2>
    @include('partials.explore-list', ['sites' => app(\App\Showcase\Explore::class)->listed(config('showcase.explore.on_home'))])
    <p class="mt-4"><a href="{{ route('explore') }}" class="text-sm text-teal-300 underline">Explore every free site</a></p>
</section>

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
    <p class="mt-2 text-sm text-neutral-500">Monthly, in US dollars, cancel any time. <a href="{{ route('pricing') }}" class="underline">Pricing details</a>.</p>
</section>

<section id="source" class="mt-24" aria-labelledby="source-heading">
    <h2 id="source-heading" class="text-2xl font-semibold text-white">The code is public</h2>
    <p class="mt-3 max-w-2xl text-neutral-400">Every line of codeinchrome is on GitHub: the control panel, the agent that runs on each server, the editor and the scripts that set it all up. Read how your site is isolated, check our security for yourself, report a problem or send an improvement.</p>
    <ul class="mt-4 max-w-2xl space-y-2 text-sm text-neutral-400">
        <li><strong class="text-neutral-200">Free to read, change and contribute to.</strong> The one thing it may not be used for is a competing commercial product or service. Each version becomes available under the Apache 2.0 licence two years after its release (<a href="{{ config('legal.source.url') }}/blob/main/LICENSE.md" class="underline" rel="noopener">{{ config('legal.source.license') }}</a>).</li>
        <li><strong class="text-neutral-200">Every change is reviewed.</strong> Pull requests are welcome, and none is merged without our review and passing tests.</li>
        <li><strong class="text-neutral-200">Found a security problem?</strong> Report it privately, never in a public issue: <a href="{{ config('legal.source.url') }}/security/policy" class="underline" rel="noopener">our security policy</a>.</li>
    </ul>
    <p class="mt-5"><a href="{{ config('legal.source.url') }}" rel="noopener" class="inline-block rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-200 hover:border-neutral-500">View the code on GitHub</a></p>
</section>
@endsection
