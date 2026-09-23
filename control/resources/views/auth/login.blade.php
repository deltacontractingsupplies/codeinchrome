@extends('layout')
@section('title', 'Sign in')
@section('content')
<div class="mx-auto max-w-sm py-10">
    <h1 class="text-2xl font-semibold text-white">Sign in</h1>
    @include('auth.social')
    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-4">
        @csrf
        <div>
            <label for="email" class="block text-sm text-neutral-300">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required
                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
            @error('email')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="password" class="block text-sm text-neutral-300">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required
                   class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
        </div>
        <button class="w-full rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Sign in</button>
    </form>
    @if (config('fleet.mail_enabled'))
        <p class="mt-4 text-sm"><a href="{{ route('password.request') }}" class="text-neutral-400 hover:text-neutral-200">Forgot your password?</a></p>
    @endif
    <p class="mt-6 text-sm text-neutral-500">
        No account? <a href="{{ route('register') }}" class="text-teal-400 hover:text-teal-300">Start free</a>
    </p>
</div>
@endsection
