{{-- The address of each listed free site, and nothing else (App\Showcase\Explore).
     nofollow ugc: built by customers, not vouched for by us. --}}
@if ($sites === [])
    <p class="mt-4 text-sm text-neutral-500" data-explore-empty>No free sites to show yet. Yours could be the first.</p>
@else
    <ul class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" data-explore-list>
        @foreach ($sites as $domain)
            <li><a href="https://{{ $domain }}" rel="nofollow ugc noopener noreferrer" target="_blank"
                   class="block truncate rounded-md border border-neutral-800 px-3 py-2 text-sm text-neutral-300 hover:border-neutral-600">{{ $domain }} <span aria-hidden="true">&nearr;</span></a></li>
        @endforeach
    </ul>
@endif
<p class="mt-3 text-xs text-neutral-500">
    Every site on the free plan is listed here by its address, and only its address - never who made it or anything inside it.
    Built by our users, not checked by us. Something wrong with one? <a href="mailto:{{ config('legal.support_email') }}?subject=Report%20a%20site" class="underline">Report it</a>.
</p>
