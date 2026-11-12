{{--
    Example sign-in page. Move it out of components/ (e.g. to resources/views/auth/login.blade.php)
    and adjust the routes.

    Controller sketch (each step is one AuthEngine call):

        $result = AuthEngine::attempt($request->input('identifier'), $request->input('password'));

        if ($result->ok())          return redirect()->intended('/dashboard');
        if ($result->requiresMfa()) return redirect('/mfa');

        return back()->withInput($request->only('identifier'))->withErrors([
            'form' => $result->retryAfter > 0
                ? "Too many attempts. Try again in {$result->retryAfter} seconds."
                : 'These credentials do not match our records.',
        ]);

    Tailwind is loaded from the Play CDN so the page works out of the box; for production,
    compile Tailwind (Vite) and add resources/views/components/auth to its content paths.
--}}
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-full antialiased">
    <x-auth.auth-card title="Welcome back" subtitle="Sign in to your account to continue">
        <x-auth.credential-form action="/login" forgot-password-href="/forgot-password" />

        @if (AuthEngine::config()->isDriverEnabled('webauthn'))
            <x-auth.passkey-button options-url="/passkey/options" verify-url="/passkey/verify" redirect-to="/dashboard" />
        @endif

        <x-auth.social-login-row :providers="AuthEngine::getActiveProviders()" />

        <x-slot:footer>
            No account yet?
            <a href="/register" class="font-medium text-indigo-600 transition hover:text-indigo-500">Create one</a>
        </x-slot:footer>
    </x-auth.auth-card>
</body>
</html>
