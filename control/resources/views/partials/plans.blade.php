<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach (config('billing.plans') as $key => $plan)
        <div class="rounded-lg border border-neutral-800 p-5 {{ $key === 'pro' ? 'ring-1 ring-teal-500/40' : '' }}">
            <div class="font-medium text-neutral-100">{{ $plan['name'] }}</div>
            <div class="mt-1 text-2xl font-semibold text-white">
                ${{ $plan['price'] }}<span class="text-sm font-normal text-neutral-500">/mo</span>
            </div>
            <ul class="mt-4 space-y-1 text-sm text-neutral-400">
                <li>{{ $plan['sites'] }} {{ Str::plural('site', $plan['sites']) }}</li>
                <li>{{ $plan['cpu'] }} CPU &middot; {{ $plan['memory'] }} RAM</li>
                <li>{{ $plan['disk_gb'] }} GB disk</li>
                <li>{{ $plan['custom_domains'] ? 'Custom domains' : 'Free subdomain' }}</li>
            </ul>
            @include('partials.stock', ['left' => $stock[$key] ?? null])
        </div>
    @endforeach
</div>
