{{-- How many more of a paid plan the fleet can take (App\Fleet\Stock), counting down as they sell. --}}
@if ($left === 0)
    <span class="rounded-full border border-amber-800 px-2.5 py-0.5 text-xs font-medium text-amber-300" data-stock="out">Out of stock</span>
@elseif (is_int($left))
    <span class="rounded-full border {{ $left <= 3 ? 'border-amber-800 text-amber-300' : 'border-teal-800 text-teal-300' }} px-2.5 py-0.5 text-xs font-medium" data-stock="{{ $left }}">
        {{ $left <= 3 ? "Only $left left" : "$left available" }}
    </span>
@endif
