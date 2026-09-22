@extends('layout')
@section('title', 'Choose a new password')
@section('content')
<div class="mx-auto max-w-sm py-10">
    <h1 class="text-2xl font-semibold text-white">Choose a new password</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-3">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        @foreach ([['password','New password'],['password_confirmation','Confirm new password']] as [$f,$l])
            <label for="{{ $f }}" class="block text-sm text-neutral-300">{{ $l }}</label>
            <input id="{{ $f }}" name="{{ $f }}" type="password" required autocomplete="new-password"
                   class="w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
        @endforeach
        @error('email')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
        @error('password')<p class="text-sm text-red-400">{{ $message }}</p>@enderror
        <button class="w-full rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950">Reset password</button>
    </form>
</div>
@endsection
