@extends('layout')
@section('title', $site->site_id.' settings')
@section('content')
<p class="text-sm"><a href="{{ route('dashboard') }}" class="text-neutral-500 hover:text-neutral-300">Sites</a> <span class="text-neutral-600">/</span> {{ $site->domain }}</p>
<h1 class="mt-2 text-2xl font-semibold text-white">Settings</h1>
<p class="mt-2 text-sm text-neutral-400"><a href="{{ route('sites.backups', $site) }}" class="text-teal-300 underline">Backups</a> - nightly copies of the files and database, and restore.</p>

<section class="mt-8 max-w-2xl">
    <h2 class="text-lg font-medium text-neutral-100">Background processes</h2>
    <p class="mt-1 text-sm text-neutral-400">
        Run beside your site's web server, in the same container, and restarted if they stop.
        Each one takes some of the plan's memory, so turning one on leaves a little less for web requests.
        Saving restarts the site, which takes a few seconds.
    </p>

    @unless ($allowed)
        <p class="mt-4 rounded-md border border-amber-800 bg-amber-950/40 px-4 py-3 text-sm text-amber-200">
            Background processes come with the paid plans. <a href="{{ route('billing') }}" class="underline">See plans</a>.
        </p>
    @endunless

    <form method="POST" action="{{ route('sites.background', $site) }}" class="mt-6 space-y-4">@csrf @method('PUT')
        @foreach ([
            'queue' => ['Queue worker', 'php artisan queue:work - runs your queued jobs (emails, exports, anything dispatched). Recycled hourly and whenever it grows past 96 MB.'],
            'scheduler' => ['Scheduler', 'php artisan schedule:work - runs the tasks in routes/console.php on their schedule. Replaces the cron line you would add on a server.'],
            'reverb' => ['WebSockets (Laravel Reverb)', 'php artisan reverb:start - real-time events for your visitors (chat, notifications, live dashboards), served on your site\'s own address.'],
        ] as $key => [$label, $help])
            <label class="flex gap-3 rounded-lg border border-neutral-800 p-4 {{ $allowed ? 'cursor-pointer hover:border-neutral-600' : 'opacity-60' }}">
                <input type="checkbox" name="{{ $key }}" value="1" class="mt-1" @checked($site->{$key}) @disabled(! $allowed)>
                <span>
                    <span class="font-medium text-neutral-100">{{ $label }}</span>
                    <span class="mt-1 block text-sm text-neutral-400">{{ $help }}</span>
                </span>
            </label>
        @endforeach
        <button class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400" @disabled(! $allowed)>Save and restart</button>
    </form>

    <details class="mt-8 text-sm text-neutral-400">
        <summary class="cursor-pointer text-neutral-200">Setting up Reverb in your app</summary>
        <ol class="mt-3 list-decimal space-y-2 pl-6">
            <li>In the editor's terminal: <code class="text-neutral-200">composer require -W laravel/reverb</code> (-W lets Composer move the shared HTTP libraries to versions Reverb supports)</li>
            <li>Turn on <em>WebSockets</em> above and save.</li>
            <li>In <code class="text-neutral-200">.env</code>, where your app sends events - Reverb inside the same container:
                <code class="text-neutral-200">BROADCAST_CONNECTION=reverb</code>,
                <code class="text-neutral-200">REVERB_HOST=127.0.0.1</code>,
                <code class="text-neutral-200">REVERB_PORT=8081</code>,
                <code class="text-neutral-200">REVERB_SCHEME=http</code>.</li>
            <li>And where your visitors' browsers connect (Laravel Echo reads these):
                <code class="text-neutral-200">VITE_REVERB_HOST={{ $site->domain }}</code>,
                <code class="text-neutral-200">VITE_REVERB_PORT=443</code>,
                <code class="text-neutral-200">VITE_REVERB_SCHEME=https</code>. Then rebuild your assets.</li>
            <li>Browsers connect to <code class="text-neutral-200">wss://{{ $site->domain }}/app/…</code>. Only that path is public; Reverb's publishing API is reachable from your app alone.</li>
        </ol>
    </details>
</section>
@endsection
