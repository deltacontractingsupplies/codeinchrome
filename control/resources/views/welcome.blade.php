@extends('layout')
@section('title', 'codeinchrome — Laravel hosting the AI can drive')
@section('content')
<div class="py-12 sm:py-20">
    <h1 class="text-4xl sm:text-5xl font-semibold tracking-tight text-white max-w-2xl">
        Laravel hosting the AI can drive, and you can leave.
    </h1>
    <p class="mt-5 max-w-2xl text-lg text-neutral-400">
        A real Laravel app on a real server, live on HTTPS in seconds. The agent builds it
        through the browser. The code is yours — clone it, move it, host it elsewhere the day
        you want to.
    </p>

    <div class="mt-8 flex flex-wrap gap-3">
        <a href="{{ route('register') }}" class="rounded-md bg-teal-500 px-5 py-2.5 font-medium text-neutral-950 hover:bg-teal-400">Start free</a>
        <a href="#plans" class="rounded-md border border-neutral-700 px-5 py-2.5 text-neutral-200 hover:border-neutral-500">See plans</a>
    </div>

    <dl class="mt-16 grid gap-8 sm:grid-cols-3">
        @foreach ([
            ['Isolated, not shared', 'Every site is its own container with its own network, capped CPU and memory, and no capabilities. One customer cannot reach another — we test that on every host.'],
            ['Only public/ is served', 'The document root cannot be moved above public/. Your .env, vendor and storage are not reachable over HTTP, by construction rather than by config.'],
            ['Yours to take', 'Standard Laravel, standard MySQL, standard git. No lock-in runtime, no proprietary build step, nothing to port when you leave.'],
        ] as [$title, $body])
            <div>
                <dt class="font-medium text-neutral-100">{{ $title }}</dt>
                <dd class="mt-2 text-sm text-neutral-400 leading-relaxed">{{ $body }}</dd>
            </div>
        @endforeach
    </dl>

    <h2 id="plans" class="mt-20 text-2xl font-semibold text-white">Plans</h2>
    @include('partials.plans')
    <p class="mt-4 text-sm text-neutral-500">Monthly, in US dollars, cancel any time. <a href="{{ route('pricing') }}" class="underline">Pricing details</a>.</p>
</div>
@endsection
