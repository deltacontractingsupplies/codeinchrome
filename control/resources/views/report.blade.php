@extends('layout')
@section('title', 'Report a site')
@section('content')
<div class="mx-auto max-w-xl py-10">
    <h1 class="text-2xl font-semibold text-white">Report a site</h1>
    <p class="mt-2 text-sm text-neutral-400">
        Seen phishing, malware, a scam or anything else that breaks our <a href="{{ route('terms') }}#acceptable-use" class="underline">rules</a> on a site hosted here?
        Tell us. Every report is read, and a site that breaks the rules is taken down.
        For a security problem in codeinchrome itself, see our <a href="{{ config('legal.source.url') }}/security/policy" class="underline" rel="noopener">security policy</a>.
    </p>
    @if (session('status'))
        <p class="mt-6 rounded-md border border-teal-800 bg-teal-950/40 p-3 text-sm text-teal-200" data-report-received>{{ session('status') }}</p>
    @endif
    <form method="POST" action="{{ route('report.store') }}" class="mt-8 space-y-4">
        @csrf
        <div>
            <label for="url" class="block text-sm text-neutral-300">Address of the page</label>
            <input id="url" name="url" type="url" required value="{{ old('url', $url) }}" placeholder="https://example.codeinchrome.com/..."
                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
            @error('url')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="reason" class="block text-sm text-neutral-300">What is wrong</label>
            <select id="reason" name="reason" required class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
                @foreach ($reasons as $key => $label)
                    <option value="{{ $key }}" @selected(old('reason') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @error('reason')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="details" class="block text-sm text-neutral-300">Details <span class="text-neutral-500">(optional)</span></label>
            <textarea id="details" name="details" rows="4" maxlength="4000" class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">{{ old('details') }}</textarea>
        </div>
        <div>
            <label for="email" class="block text-sm text-neutral-300">Your email <span class="text-neutral-500">(optional, if you want a reply)</span></label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
        </div>
        <button class="rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Send report</button>
    </form>
    <p class="mt-6 text-xs text-neutral-500">Content that sexualises children is also reported by us to the authorities. If someone is in immediate danger, contact the police.</p>
</div>
@endsection
