@extends('layout')
@section('title', 'Two-factor authentication')
@section('content')
<div class="mx-auto max-w-sm py-10">
    <h1 class="text-2xl font-semibold text-white">Enter your code</h1>
    <p class="mt-2 text-sm text-neutral-400">From your authenticator app.</p>
    <form method="POST" action="{{ url('/two-factor-challenge') }}" class="mt-6 space-y-3">@csrf
        <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" autofocus
               class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 font-mono text-neutral-100">
        @error('code')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
        <button class="w-full rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950">Continue</button>
    </form>
    <details class="mt-6 text-sm text-neutral-400">
        <summary class="cursor-pointer">Use a recovery code instead</summary>
        <form method="POST" action="{{ url('/two-factor-challenge') }}" class="mt-3 flex gap-2">@csrf
            <input name="recovery_code" autocomplete="off" placeholder="abcde-fghij"
                   class="flex-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 font-mono text-neutral-100">
            <button class="rounded-md border border-neutral-700 px-3 py-2 text-neutral-200">Use</button>
        </form>
    </details>
</div>
@endsection
