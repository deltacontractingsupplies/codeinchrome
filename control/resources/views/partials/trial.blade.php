{{-- Where a free account stands: its trial clock, or its paused sites and their deletion date. --}}
@php($u = auth()->user())
@if ($u && $u->suspended_at && ! $u->isPaid())
    <div class="mt-6 rounded-md border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-200" role="status" data-trial="paused">
        @if ($u->sites()->exists())
            Your free trial has ended and your sites are paused. They will be deleted, with their files and databases,
            on <strong>{{ $u->deletesAt()->utc()->format('j F \a\t H:i') }} UTC</strong>.
            Upgrade to Starter to bring them back exactly as they were, or download each site's database first.
        @else
            Your free trial has ended. Upgrade to Starter to build again.
        @endif
        @unless (request()->routeIs('billing'))
            <a href="{{ route('billing') }}" class="ml-1 font-medium underline">Upgrade</a>
        @endunless
    </div>
@elseif ($u?->trialExpired())
    <div class="mt-6 rounded-md border border-red-800 bg-red-950/40 px-4 py-3 text-sm text-red-200" role="status" data-trial="ended">
        Your free trial has ended. Upgrade to Starter to keep building.
        @unless (request()->routeIs('billing'))<a href="{{ route('billing') }}" class="ml-1 font-medium underline">Upgrade</a>@endunless
    </div>
@elseif ($u?->onTrial())
    <div class="mt-6 rounded-md border border-teal-800 bg-teal-950/30 px-4 py-3 text-sm text-teal-200" role="status" data-trial="running">
        Free trial: <strong>{{ $u->trial_ends_at->diffForHumans(['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }}</strong> left
        (ends {{ $u->trial_ends_at->utc()->format('j F, H:i') }} UTC). After that the site is paused, then deleted
        {{ config('billing.trial.grace_days') }} days later unless you upgrade.
        @unless (request()->routeIs('billing'))<a href="{{ route('billing') }}" class="ml-1 font-medium underline">Upgrade</a>@endunless
    </div>
@endif
@if ($u?->storage_over_at)
    <div class="mt-6 rounded-md border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200" role="status" data-storage="over">
        Your sites use more than your plan's {{ $u->planConfig()['storage_gb'] }} GB of storage. New sites and uploads are paused until
        you are back under; your sites keep running. Keep uploads on Cloudflare R2 or S3 to free space - each site's settings page shows how.
    </div>
@endif
