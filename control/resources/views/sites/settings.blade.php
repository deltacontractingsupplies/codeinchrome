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
            Background processes come with Starter. <a href="{{ route('billing') }}" class="underline">Upgrade</a>.
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

<section class="mt-12 max-w-2xl" aria-labelledby="php-heading">
    <h2 id="php-heading" class="text-lg font-medium text-neutral-100">PHP</h2>
    <p class="mt-1 text-sm text-neutral-400">
        Leave a field empty for the default. Saving restarts the site, which takes a few seconds.
        Error display stays off: it would show your visitors stack traces. Your app's errors are in the editor's Logs.
    </p>
    @php($php = $site->php_settings ?? [])
    <form method="POST" action="{{ route('sites.php', $site) }}" class="mt-6 grid gap-4 sm:grid-cols-3">@csrf @method('PUT')
        @foreach ([
            ['memory_mb', 'memoryMB', 'Memory limit', 'MB', 'Default 256 MB'],
            ['max_execution_seconds', 'maxExecutionSeconds', 'Maximum execution time', 'seconds', 'Default 30 s'],
            ['upload_mb', 'uploadMB', 'Largest upload', 'MB', 'Default 32 MB'],
        ] as [$field, $key, $label, $unit, $hint])
            <label class="block text-sm">
                <span class="text-neutral-200">{{ $label }}</span>
                <span class="mt-1 flex items-center gap-2">
                    <input type="number" name="{{ $field }}" min="0" value="{{ old($field, $php[$key] ?? '') }}" placeholder="{{ $hint }}"
                           class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
                    <span class="text-neutral-500">{{ $unit }}</span>
                </span>
            </label>
        @endforeach
        <div class="sm:col-span-3">
            <button class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Save PHP settings</button>
        </div>
    </form>
</section>

<section class="mt-12 max-w-2xl" aria-labelledby="storage-heading">
    <h2 id="storage-heading" class="text-lg font-medium text-neutral-100">Keep uploads on Cloudflare R2 or S3</h2>
    <p class="mt-1 text-sm text-neutral-400">
        Your plan's {{ $site->user->planConfig()['storage_gb'] }} GB covers your sites' files and databases. User uploads grow without end,
        so keep them in object storage: Laravel supports it out of the box, and nothing stored there counts toward your plan.
        Cloudflare R2 charges nothing for downloads; Amazon S3 works the same way.
    </p>
    <ol class="mt-4 list-decimal space-y-3 pl-5 text-sm text-neutral-300">
        <li>Create a bucket and an API token with read and write access to it (in Cloudflare: R2, then Manage API tokens).</li>
        <li>In the editor's console, run <code class="rounded bg-neutral-900 px-1.5 py-0.5 text-neutral-100">composer require league/flysystem-aws-s3-v3 "^3.0"</code></li>
        <li>Add the keys to <code class="text-neutral-100">.env</code>:
<pre tabindex="0" aria-label="The .env settings for object storage" class="mt-2 overflow-x-auto rounded-md border border-neutral-800 bg-neutral-900 p-3 text-xs leading-5 text-neutral-200">FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your-key-id
AWS_SECRET_ACCESS_KEY=your-secret
AWS_BUCKET=your-bucket
# Cloudflare R2 only:
AWS_DEFAULT_REGION=auto
AWS_ENDPOINT=https://&lt;account-id&gt;.r2.cloudflarestorage.com
AWS_URL=https://&lt;your public bucket address&gt;
# Amazon S3 instead: AWS_DEFAULT_REGION=eu-central-1 (your bucket's region), and no AWS_ENDPOINT</pre>
        </li>
        <li>Store files with <code class="text-neutral-100">Storage::put(...)</code> or <code class="text-neutral-100">$request->file('photo')->store('photos')</code>, and link to them with <code class="text-neutral-100">Storage::url(...)</code>. With <code class="text-neutral-100">FILESYSTEM_DISK=s3</code> these already go to the bucket.</li>
    </ol>
    <p class="mt-3 text-sm text-neutral-500">Or ask the agent in the editor: "move uploads to Cloudflare R2". The keys stay in <code>.env</code>, which is never served.</p>
</section>
@endsection
