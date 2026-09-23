{{-- Availability of a paid plan (App\Fleet\Stock). Nothing is shown when plenty is left. --}}
@if ($left === 0)
    <p class="mt-3 text-sm font-medium text-amber-300" data-stock="out">Out of stock</p>
@elseif (is_int($left) && $left <= 3)
    <p class="mt-3 text-sm text-amber-300" data-stock="{{ $left }}">Only {{ $left }} left</p>
@endif
