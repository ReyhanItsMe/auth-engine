{{--
    PasskeyButton — WebAuthn sign-in / registration (FaceID, TouchID, Windows Hello).

    <x-auth.passkey-button :options-url="route('passkey.options')" :verify-url="route('passkey.verify')" redirect-to="/dashboard" />
    <x-auth.passkey-button mode="register" :options-url="route('passkey.register.options')" :verify-url="route('passkey.register.verify')" />

    options-url  POST route returning AuthEngine::webauthn()->authenticationOptions()
                 (or ->registrationOptions(AuthEngine::user()) in register mode)
    verify-url   POST route passing $request->all() to AuthEngine::webauthn()->authenticate(...)
                 (or ->register(AuthEngine::user(), ...)) and returning the result as JSON
--}}
@props(['mode' => 'login', 'optionsUrl' => '', 'verifyUrl' => '', 'redirectTo' => '', 'label' => null])

@php $register = $mode === 'register'; @endphp

<div data-auth-passkey hidden
     data-mode="{{ $register ? 'register' : 'login' }}"
     data-options-url="{{ $optionsUrl }}"
     data-verify-url="{{ $verifyUrl }}"
     data-redirect-to="{{ $redirectTo }}"
     data-csrf="{{ csrf_token() }}">
    <button type="button" class="flex w-full items-center justify-center gap-2.5 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-60">
        <svg aria-hidden="true" viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z"/>
        </svg>
        <span data-label>{{ $label ?? ($register ? 'Add a passkey' : 'Sign in with a passkey') }}</span>
    </button>
    <p role="alert" data-error class="mt-2 hidden text-center text-sm text-red-600"></p>
</div>

@once
<script>
(function () {
  if (window.__authPasskeyReady) return;
  window.__authPasskeyReady = true;

  var toBuffer = function (value) {
    var base64 = value.replace(/-/g, '+').replace(/_/g, '/');
    var binary = atob(base64 + '==='.slice((base64.length + 3) % 4));
    return Uint8Array.from(binary, function (c) { return c.charCodeAt(0); }).buffer;
  };
  var toBase64Url = function (buffer) {
    if (!buffer) return null;
    var binary = '';
    new Uint8Array(buffer).forEach(function (b) { binary += String.fromCharCode(b); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };
  var withIds = function (list) {
    return (list || []).map(function (item) { return Object.assign({}, item, { id: toBuffer(item.id) }); });
  };

  document.querySelectorAll('[data-auth-passkey]').forEach(function (root) {
    if (!window.PublicKeyCredential) return; // unsupported browser: stay hidden
    root.hidden = false;

    var button = root.querySelector('button');
    var label = root.querySelector('[data-label]');
    var error = root.querySelector('[data-error]');
    var idle = label.textContent;

    var post = function (url, body) {
      var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
      if (root.dataset.csrf) headers['X-CSRF-TOKEN'] = root.dataset.csrf;
      return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body || {}) })
        .then(function (response) {
          if (!response.ok) throw new Error('The passkey could not be verified. Please try again.');
          return response.json();
        });
    };

    button.addEventListener('click', function () {
      button.disabled = true;
      label.textContent = 'Waiting for your device…';
      error.classList.add('hidden');

      post(root.dataset.optionsUrl).then(function (options) {
        options.challenge = toBuffer(options.challenge);
        if (root.dataset.mode === 'register') {
          options.user.id = toBuffer(options.user.id);
          options.excludeCredentials = withIds(options.excludeCredentials);
          return navigator.credentials.create({ publicKey: options });
        }
        options.allowCredentials = withIds(options.allowCredentials);
        return navigator.credentials.get({ publicKey: options });
      }).then(function (credential) {
        if (!credential) throw new Error('No passkey was selected.');
        var r = credential.response;
        return post(root.dataset.verifyUrl, {
          id: credential.id,
          rawId: toBase64Url(credential.rawId),
          type: credential.type,
          response: {
            clientDataJSON: toBase64Url(r.clientDataJSON),
            attestationObject: toBase64Url(r.attestationObject),
            authenticatorData: toBase64Url(r.authenticatorData),
            signature: toBase64Url(r.signature),
            userHandle: toBase64Url(r.userHandle),
            transports: typeof r.getTransports === 'function' ? r.getTransports() : []
          }
        });
      }).then(function () {
        if (root.dataset.redirectTo) window.location.assign(root.dataset.redirectTo);
        else window.location.reload();
      }).catch(function (caught) {
        // NotAllowedError = the user dismissed the browser prompt.
        if (caught && caught.name !== 'NotAllowedError') {
          error.textContent = caught.message || 'Something went wrong.';
          error.classList.remove('hidden');
        }
      }).finally(function () {
        button.disabled = false;
        label.textContent = idle;
      });
    });
  });
})();
</script>
@endonce
