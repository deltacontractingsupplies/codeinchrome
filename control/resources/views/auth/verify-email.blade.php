@extends('layout')
@section('title', 'Confirm your email')
@section('content')
<div class="mx-auto max-w-md py-10">
    <h1 class="text-2xl font-semibold text-white">Confirm your email</h1>
    <p class="mt-3 text-sm text-neutral-400">We sent a link to <strong class="text-neutral-200">{{ auth()->user()->email }}</strong>.
        Open it to start creating sites. It can take a minute to arrive; check spam if it does not.</p>
    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">@csrf
        <button class="rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-200 hover:border-neutral-500">Send it again</button>
    </form>
</div>
@endsection
