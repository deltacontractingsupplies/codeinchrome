@php($trial = config('billing.trial'))
@php($selling = \App\Billing\Sales::open())
<div class="mt-6 grid gap-4 {{ count(\App\Billing\Sales::plans()) > 1 ? 'md:grid-cols-2' : '' }} max-w-4xl">
    @foreach (\App\Billing\Sales::plans() as $key => $plan)
        @php($paid = $plan['price'] > 0)
        @php($cap = app(\App\Billing\Capacity::class)->forPlan($key))
        <div class="flex flex-col rounded-lg border p-6 {{ $paid ? 'border-teal-700/70 ring-1 ring-teal-500/30' : 'border-neutral-800' }}" data-plan="{{ $key }}">
            <div class="flex items-start justify-between gap-3">
                <div class="font-medium text-neutral-100">{{ \App\Billing\Sales::name($plan) }}</div>
                @if ($paid)
                    @include('partials.stock', ['left' => $stock[$key] ?? null])
                @endif
            </div>
            <div class="mt-1 text-3xl font-semibold text-white">
                @if ($paid)
                    ${{ $plan['price'] }}<span class="text-sm font-normal text-neutral-500">/month</span>
                @elseif ($selling)
                    $0<span class="text-sm font-normal text-neutral-500"> for {{ $trial['days'] }} days, no card</span>
                @else
                    $0<span class="text-sm font-normal text-neutral-500"> - free, no card</span>
                @endif
            </div>
            <ul class="mt-5 space-y-1.5 text-sm text-neutral-300">
                <li>{{ $plan['sites'] }} {{ Str::plural('site', $plan['sites']) }}</li>
                {{-- Each site's files: disk_gb (its own disk). Files and databases together: storage_gb for the account. Capacity: PER SITE. --}}
                @if ($plan['sites'] > 1)
                    <li>{{ $plan['disk_gb'] }} GB of storage for each site - {{ $plan['storage_gb'] }} GB in all (files and databases)</li>
                @else
                    <li>{{ $plan['storage_gb'] }} GB of storage for your site (files and databases)</li>
                @endif
                @if ($cap)
                    <li>{{ $plan['sites'] > 1 ? 'Each site' : 'Your site' }}: up to ~{{ number_format($cap['concurrent_visitors']) }} visitors at once <span class="text-neutral-500">({{ $cap['page_views_per_second'] }} page views a second, <a href="{{ route('pricing') }}#capacity" class="underline">measured</a>)</span></li>
                    {{-- WebSockets run on Reverb, a background process: only plans with background processes have them. --}}
                    @if ($cap['websocket_connections'] && ($plan['background'] ?? false))
                        <li>{{ $plan['sites'] > 1 ? 'Each site' : 'Your site' }}: up to ~{{ $cap['websocket_label'] }} live WebSocket connections</li>
                    @endif
                @elseif (! $paid)
                    <li>The same speed as Starter</li>
                @endif
                <li>{{ $plan['custom_domains'] ? 'Your own domains, with HTTPS' : 'A free .codeinchrome.com address' }}</li>
                @if ($plan['background'] ?? false)
                    <li>Queue worker, scheduler and Reverb WebSockets</li>
                @endif
                <li>Nightly backups and every file change kept as a version</li>
                <li>Works with <a href="{{ config('agent.extension.page') }}" rel="noopener noreferrer" target="_blank" class="underline">Claude in Chrome</a>: the extension builds your site in the editor, right in your browser</li>
            </ul>
            @unless ($paid)
                <p class="mt-4 text-xs leading-relaxed text-neutral-500" data-free-terms>
                    @if ($selling)
                        When the trial ends the site is paused, and deleted {{ $trial['grace_days'] }} days later unless you upgrade.
                        Upgrade any time and it carries on exactly as it was.
                    @else
                        Paid plans open soon. Until then your site keeps running; before any trial clock starts,
                        you get an email and a full {{ $trial['days'] }} days to decide.
                    @endif
                </p>
            @endunless
        </div>
    @endforeach
</div>
<p class="mt-4 max-w-4xl text-sm text-neutral-500">
    Storing lots of uploads? Laravel keeps them on Cloudflare R2 or Amazon S3 with a few lines of configuration,
    and files kept there do not count toward your storage.
</p>
