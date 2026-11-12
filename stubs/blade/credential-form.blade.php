{{--
    CredentialForm — sign-in / sign-up form. Submits: identifier, password (and name when registering).

    <x-auth.credential-form :action="route('login')" forgot-password-href="/forgot-password" />
    <x-auth.credential-form :action="route('register')" mode="register" identifier-label="Email or username" identifier-type="text" />

    Errors come from the "identifier", "password", "name" and "form" keys of Laravel's $errors bag.
--}}
@props([
    'action' => '',
    'mode' => 'login',
    'identifierLabel' => 'Email',
    'identifierType' => 'email',
    'forgotPasswordHref' => null,
    'minPasswordLength' => 8,
])

@php
    $register = $mode === 'register';
    $input = 'block w-full rounded-lg border-0 px-3.5 py-2.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600';
@endphp

<form method="post" action="{{ $action }}" {{ $attributes->merge(['class' => 'space-y-5']) }}>
    @csrf

    @error('form')
        <div role="alert" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200">{{ $message }}</div>
    @enderror

    @if ($register)
        <div>
            <label for="auth-name" class="mb-1.5 block text-sm font-medium text-slate-700">Name</label>
            <input id="auth-name" name="name" type="text" autocomplete="name" value="{{ old('name') }}" class="{{ $input }}">
            @error('name') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif

    <div>
        <label for="auth-identifier" class="mb-1.5 block text-sm font-medium text-slate-700">{{ $identifierLabel }}</label>
        <input id="auth-identifier" name="identifier" type="{{ $identifierType }}" required autocomplete="username"
               value="{{ old('identifier') }}" class="{{ $input }}">
        @error('identifier') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <div class="mb-1.5 flex items-center justify-between">
            <label for="auth-password" class="block text-sm font-medium text-slate-700">Password</label>
            @if (! $register && $forgotPasswordHref)
                <a href="{{ $forgotPasswordHref }}" class="text-sm font-medium text-indigo-600 transition hover:text-indigo-500">Forgot password?</a>
            @endif
        </div>
        <input id="auth-password" name="password" type="password" required
               @if ($register) minlength="{{ (int) $minPasswordLength }}" @endif
               autocomplete="{{ $register ? 'new-password' : 'current-password' }}" class="{{ $input }}">
        @error('password') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
    </div>

    <button type="submit" class="flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
        {{ $register ? 'Create account' : 'Sign in' }}
    </button>
</form>
