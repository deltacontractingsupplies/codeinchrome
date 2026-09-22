@extends('layout')
@section('title', 'Reset your password')
@section('content')
<div class="mx-auto max-w-sm py-10">
    <h1 class="text-2xl font-semibold text-white">Reset your password</h1>
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-3">@csrf
        <label for="email" class="block text-sm text-neutral-300">Email</label>
        <input id="email" name="email" type="email" required autocomplete="email"
               class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
        <button class="w-full rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950">Send reset link</button>
    </form>
</div>
@endsection
