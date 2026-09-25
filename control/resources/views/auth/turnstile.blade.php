@if (\App\Auth\Turnstile::enabled())
    {{-- Cloudflare Turnstile (App\Auth\Turnstile). Rendered by its own script
         from the class alone - no inline script - and it adds the
         cf-turnstile-response field to this form. The CSP allows its origin
         on the pages that include this (SecurityHeaders, csp_turnstile). --}}
    @php(request()->attributes->set('csp_turnstile', true))
    <div class="cf-turnstile" data-sitekey="{{ config('services.turnstile.site_key') }}" data-theme="dark"></div>
    @error('cf-turnstile-response')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
    <script src="{{ \App\Auth\Turnstile::ORIGIN }}/turnstile/v0/api.js" async defer></script>
@endif
