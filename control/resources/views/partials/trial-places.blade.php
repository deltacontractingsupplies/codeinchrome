{{-- How many more free trials fit now (App\Fleet\Stock::trialsLeft), cached a minute: a public page must not count the fleet per visitor. --}}
@php($trialsLeft = \Illuminate\Support\Facades\Cache::remember('fleet.trials_left', 60, fn () => app(\App\Fleet\Stock::class)->trialsLeft()))
@if ($trialsLeft > 0)
    <p class="mt-3 text-sm text-teal-300" data-trials-left="{{ $trialsLeft }}">{{ $trialsLeft }} free trial {{ $trialsLeft === 1 ? 'place' : 'places' }} left right now</p>
@else
    <p class="mt-3 text-sm text-amber-300" data-trials-left="0">Free trials are full right now - a place opens as soon as one frees up.</p>
@endif
