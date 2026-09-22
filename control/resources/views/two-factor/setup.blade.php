@extends('layout')
@section('title', 'Set up two-factor authentication')
@section('content')
<div class="max-w-md">
    <h1 class="text-2xl font-semibold text-white">Set up two-factor authentication</h1>
    <p class="mt-2 text-sm text-neutral-400">Scan this with an authenticator app (1Password, Google Authenticator, Authy), then enter the code it shows.</p>
    {{-- Printed unescaped, so it matters what is in it: bacon-qr-code encodes
         the otpauth URI (which includes the email address) into MODULES and
         emits only SVG path geometry. The email never appears as text, so
         nothing a customer typed can become markup here. --}}
    <div class="mt-6 inline-block rounded-lg bg-white p-3">{!! $qr !!}</div>
    <p class="mt-3 text-xs text-neutral-500">Or type this key: <code class="select-all text-neutral-300" data-secret>{{ $secret }}</code></p>
    <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-6 flex gap-2">@csrf
        <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required
               class="w-40 rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 font-mono text-neutral-100">
        <button class="rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950 hover:bg-teal-400">Turn on</button>
    </form>
    @error('code')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
</div>
@endsection
