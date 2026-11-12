{{--
    AuthCard — centered card that frames every auth screen.

    <x-auth.auth-card title="Welcome back" subtitle="Sign in to continue">
        <x-slot:footer>No account? <a href="/register">Create one</a></x-slot:footer>
        ...
    </x-auth.auth-card>
--}}
@props(['title' => 'Sign in', 'subtitle' => null, 'logo' => null, 'footer' => null])

<div class="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
    <div class="w-full max-w-md">
        <div {{ $attributes->merge(['class' => 'rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-900/5 sm:p-10']) }}>
            @if ($logo)
                <div class="mb-6 flex justify-center">{{ $logo }}</div>
            @endif
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>
                @if ($subtitle)
                    <p class="mt-2 text-sm text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            <div class="space-y-6">{{ $slot }}</div>
        </div>
        @if ($footer)
            <div class="mt-6 text-center text-sm text-slate-500">{{ $footer }}</div>
        @endif
    </div>
</div>
