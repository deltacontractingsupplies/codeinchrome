@extends('layout')
@section('title', 'Account')
@section('content')
<h1 class="text-2xl font-semibold text-white">Account</h1>
<p class="mt-1 text-sm text-neutral-400">{{ $user->email }}</p>

<section class="mt-10 max-w-md">
    <h2 class="text-lg font-medium text-white">Two-factor authentication</h2>
    @if ($user->two_factor_confirmed_at)
        <p class="mt-2 text-sm text-teal-400">On. Signing in needs a code from your authenticator app.</p>
        <form method="POST" action="{{ route('two-factor.disable') }}" class="mt-3 flex gap-2">@csrf @method('DELETE')
            <input type="password" name="password" placeholder="Current password" autocomplete="current-password" required
                   class="flex-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
            <button class="rounded-md border border-neutral-700 px-3 py-2 text-sm text-neutral-300 hover:border-red-800">Turn off</button>
        </form>
    @else
        <p class="mt-2 text-sm text-neutral-400">Off. Turn it on so a stolen password is not enough to reach your sites.</p>
        <a href="{{ route('two-factor.setup') }}" class="mt-3 inline-block rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Set up</a>
    @endif
</section>

<section class="mt-10 max-w-md">
    <h2 class="text-lg font-medium text-white">Change password</h2>
    <form method="POST" action="{{ route('account.password') }}" class="mt-3 space-y-3">@csrf @method('PUT')
        @foreach ([['current_password','Current password','current-password'],['password','New password','new-password'],['password_confirmation','Confirm new password','new-password']] as [$f,$l,$ac])
            <div>
                <label for="{{ $f }}" class="block text-sm text-neutral-300">{{ $l }}</label>
                <input id="{{ $f }}" name="{{ $f }}" type="password" autocomplete="{{ $ac }}" required
                       class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
                @error($f)<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <button class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Change password</button>
    </form>
</section>

<section class="mt-10 max-w-md">
    <h2 class="text-lg font-medium text-red-300">Delete account</h2>
    <p class="mt-2 text-sm text-neutral-400">Deletes every site, its files, its database and its domains. Backups of deleted sites are kept 30 days, then removed.</p>
    <details class="mt-3">
        <summary class="list-none cursor-pointer inline-block rounded-md border border-red-900 px-3 py-1.5 text-sm text-red-300">Delete my account</summary>
        <form method="POST" action="{{ route('account.destroy') }}" class="mt-3 flex gap-2">@csrf @method('DELETE')
            <input type="password" name="password" placeholder="Current password" autocomplete="current-password" required
                   class="flex-1 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100">
            <button class="rounded-md bg-red-700 px-3 py-2 text-sm font-medium text-white hover:bg-red-600">Delete everything</button>
        </form>
        @error('password')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
    </details>
</section>
@endsection
