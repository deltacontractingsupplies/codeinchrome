@extends('layout')
@section('title', 'Recovery codes')
@section('content')
<div class="max-w-md">
    <h1 class="text-2xl font-semibold text-white">Two-factor authentication is on</h1>
    <p class="mt-2 text-sm text-neutral-300">Save these recovery codes somewhere safe. Each works once, if you lose your phone. <strong>They will not be shown again.</strong></p>
    <ul class="mt-4 grid grid-cols-2 gap-2 rounded-lg border border-neutral-800 p-4 font-mono text-sm text-neutral-100" data-recovery-codes>
        @foreach ($codes as $c)<li class="select-all">{{ $c }}</li>@endforeach
    </ul>
    <a href="{{ route('account') }}" class="mt-6 inline-block rounded-md bg-teal-500 px-4 py-2 text-sm font-medium text-neutral-950">I have saved them</a>
</div>
@endsection
