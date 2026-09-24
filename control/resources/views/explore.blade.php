@extends('layout')
@section('title', 'Explore sites built on codeinchrome')
@section('content')
<div class="pt-6 sm:pt-12">
    <h1 class="text-3xl font-semibold text-white">Explore sites built on codeinchrome</h1>
    <p class="mt-3 max-w-2xl text-neutral-400">Laravel sites people are building with an AI agent in their browser, on the free plan. Open any of them to see what is possible.</p>
    @include('partials.explore-list', ['sites' => $sites])
</div>
@endsection
