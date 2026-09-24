@extends('layout')
@section('title', $demo['name'].' - source code - codeinchrome')
@section('content')
<div class="flex flex-wrap items-end justify-between gap-4">
    <div>
        <p class="text-sm text-neutral-500"><a href="{{ route('home') }}#demos" class="underline">Demos</a> / {{ $demo['name'] }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-white">{{ $demo['name'] }}: the source</h1>
        <p class="mt-1 max-w-2xl text-sm text-neutral-400">
            {{ $demo['built'] }} Every file of the app, read live from the running site and read-only.
            Dependencies, build output and settings (the <code>.env</code> file) are never shown.
        </p>
    </div>
    <a href="{{ $demo['url'] }}" rel="noopener" class="rounded-md border border-neutral-700 px-4 py-2 text-sm text-neutral-200 hover:border-neutral-500">Open the store &nearr;</a>
</div>

<x-code-window class="mt-6 h-[78vh]" :demo-key="$key" :domain="$domain" :tree="$tree" :path="$path" :file="$file" :count="$count" />
@vite('resources/js/demo-code.js')
@endsection
