{{--
    MfaSetupForm — enrol an authenticator app (TOTP). Submits: code.

    In the controller: $setup = AuthEngine::totp()->beginSetup(AuthEngine::user());
    <x-auth.mfa-setup-form :action="route('mfa.confirm')" :secret="$setup['secret']" :uri="$setup['uri']" />

    After AuthEngine::totp()->confirm($user, $code) returned the recovery codes:
    <x-auth.mfa-setup-form :recovery-codes="$codes" />

    The QR code is drawn in the browser (qrcode-generator, loaded with an SRI hash),
    so the secret is never sent to a third-party QR service.
--}}
@props(['action' => '', 'secret' => '', 'uri' => '', 'digits' => 6, 'recoveryCodes' => []])

@if (count($recoveryCodes) > 0)
    <div class="space-y-4">
        <div class="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200">
            Two-factor authentication is on. Save these recovery codes somewhere safe — each one works once and they will not be shown again.
        </div>
        <ul class="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm text-slate-800 ring-1 ring-inset ring-slate-200">
            @foreach ($recoveryCodes as $recoveryCode)
                <li>{{ $recoveryCode }}</li>
            @endforeach
        </ul>
    </div>
@else
    <form method="post" action="{{ $action }}" {{ $attributes->merge(['class' => 'space-y-6']) }}>
        @csrf

        <ol class="list-inside list-decimal space-y-1 text-sm text-slate-600">
            <li>Open your authenticator app (Google Authenticator, Authy, 1Password…).</li>
            <li>Scan the QR code or enter the setup key.</li>
            <li>Type the {{ (int) $digits }}-digit code the app shows.</li>
        </ol>

        <div class="flex flex-col items-center gap-4">
            <div data-auth-qr="{{ $uri }}" class="hidden h-48 w-48 rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-900/10 [&>svg]:h-full [&>svg]:w-full"></div>
            <div class="w-full">
                <p class="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">Setup key</p>
                <code class="block select-all break-all rounded-lg bg-slate-50 px-3 py-2 text-center font-mono text-sm tracking-widest text-slate-800 ring-1 ring-inset ring-slate-200">{{ $secret }}</code>
                <a href="{{ $uri }}" class="mt-2 block text-center text-sm font-medium text-indigo-600 transition hover:text-indigo-500">Open in authenticator app</a>
            </div>
        </div>

        <div>
            <label for="auth-totp-code" class="mb-1.5 block text-sm font-medium text-slate-700">Verification code</label>
            <input id="auth-totp-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*"
                   maxlength="{{ (int) $digits + 1 }}" required
                   class="block w-full rounded-lg border-0 px-3.5 py-2.5 text-center font-mono text-lg tracking-[0.4em] text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600">
            @error('code') <p class="mt-1.5 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
            Turn on two-factor authentication
        </button>
    </form>

    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js" integrity="sha384-8FWZA6BGMXhsfO+BLtrJK0We6gg5o1JyO8xQm6peWDEUs17ACA5ziE/NIAkl9z2k" crossorigin="anonymous"></script>
    <script>
        document.querySelectorAll('[data-auth-qr]').forEach(function (box) {
            if (typeof qrcode !== 'function' || !box.dataset.authQr) return;
            var qr = qrcode(0, 'M');
            qr.addData(box.dataset.authQr);
            qr.make();
            box.innerHTML = qr.createSvgTag(4, 0);
            var svg = box.querySelector('svg');
            if (svg) { svg.removeAttribute('width'); svg.removeAttribute('height'); }
            box.classList.remove('hidden');
        });
    </script>
@endif
