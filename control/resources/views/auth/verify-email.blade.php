@extends('layout')
@section('title', 'Confirm your email')
@section('content')
<div class="mx-auto max-w-md py-10">
    <h1 class="text-2xl font-semibold text-white">Confirm your email</h1>
    <p class="mt-3 text-sm text-neutral-400">We sent a six-digit code to <strong class="text-neutral-200">{{ auth()->user()->email }}</strong>.
        Type it below, or use the link in the same email. It can take a minute to arrive; check spam if it does not.</p>

    <form method="POST" action="{{ route('verification.code') }}" class="mt-6 flex gap-2">@csrf
        <label for="code" class="sr-only">Confirmation code</label>
        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123 456" maxlength="20" required autofocus
               class="flex-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-lg tracking-widest text-neutral-100">
        <button class="rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Confirm</button>
    </form>
    @error('code')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">@csrf
        <button class="text-sm text-neutral-400 underline hover:text-neutral-200">Send a new code</button>
    </form>
</div>
@endsection
