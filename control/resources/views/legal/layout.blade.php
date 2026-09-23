@extends('layout')
@section('content')
<article class="legal max-w-3xl">
    <h1 class="text-3xl font-semibold tracking-tight text-white">@yield('heading')</h1>
    <p class="mt-2 text-sm text-neutral-500">Last updated {{ config('legal.updated') }}</p>
    <div class="mt-8 space-y-4 text-neutral-300 leading-relaxed">
        @yield('body')
    </div>
</article>
@endsection
