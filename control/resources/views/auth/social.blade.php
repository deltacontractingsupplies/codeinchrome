{{-- Sign in with Google / Apple, for the providers that are configured. --}}
@php($providers = \App\Http\Controllers\SocialLoginController::enabled())
@if ($providers)
    <div class="mt-6 space-y-2">
        @foreach ($providers as $p)
            <a href="{{ route('social.redirect', $p) }}"
               class="flex w-full items-center justify-center gap-2 rounded-md border border-neutral-700 px-4 py-2 text-sm font-medium text-neutral-100 hover:border-neutral-500">
                Continue with {{ $p === 'apple' ? 'Apple' : 'Google' }}
            </a>
        @endforeach
    </div>
    <p class="mt-4 text-center text-xs text-neutral-500">or with your email</p>
@endif
