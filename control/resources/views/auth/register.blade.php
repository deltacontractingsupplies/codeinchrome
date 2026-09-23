@extends('layout')
@section('title', 'Create your account')
@section('content')
<div class="mx-auto max-w-sm py-10">
    <h1 class="text-2xl font-semibold text-white">Create your account</h1>
    <p class="mt-2 text-sm text-neutral-400">The free plan includes one site on a codeinchrome.com subdomain.</p>

    @include('auth.social')
    <form method="POST" action="{{ route('register') }}" class="mt-8 space-y-4">
        @csrf
        @foreach ([['name','Name','text'],['email','Email','email'],['password','Password','password'],['password_confirmation','Confirm password','password']] as [$field,$label,$type])
            <div>
                <label for="{{ $field }}" class="block text-sm text-neutral-300">{{ $label }}</label>
                <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}"
                       value="{{ $type === 'password' ? '' : old($field) }}"
                       autocomplete="{{ $type === 'password' ? 'new-password' : $field }}" required
                       class="mt-1 w-full rounded-md border border-neutral-700 bg-neutral-900 px-3 py-2 text-neutral-100 focus:border-teal-500 focus:outline-none">
                @error($field)<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
            </div>
        @endforeach
        <button class="w-full rounded-md bg-teal-500 px-4 py-2 font-medium text-neutral-950 hover:bg-teal-400">Create account</button>
    </form>

    <p class="mt-6 text-sm text-neutral-500">
        Already have one? <a href="{{ route('login') }}" class="text-teal-400 hover:text-teal-300">Sign in</a>
    </p>
</div>
@endsection
