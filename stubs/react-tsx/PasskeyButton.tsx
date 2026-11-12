import { useState } from 'react';

export interface PasskeyButtonProps {
  /** "login" signs in with a passkey; "register" adds one to the signed-in account. */
  mode?: 'login' | 'register';
  /** POST endpoint returning Auth::webauthn()->authenticationOptions() or ->registrationOptions($user) as JSON. */
  optionsUrl: string;
  /** POST endpoint passing the JSON body to Auth::webauthn()->authenticate() or ->register($user, ...). */
  verifyUrl: string;
  /** Sent as the X-CSRF-TOKEN header with both requests. */
  csrfToken?: string;
  label?: string;
  /** Called with the JSON answer of verifyUrl after a successful ceremony. */
  onSuccess?: (result: unknown) => void;
  onError?: (message: string) => void;
}

const toBuffer = (value: string): ArrayBuffer => {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
  return Uint8Array.from(binary, (char) => char.charCodeAt(0)).buffer;
};

const toBase64Url = (buffer: ArrayBuffer | null): string | null => {
  if (!buffer) {
    return null;
  }
  let binary = '';
  new Uint8Array(buffer).forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
};

export function PasskeyButton({
  mode = 'login',
  optionsUrl,
  verifyUrl,
  csrfToken,
  label,
  onSuccess,
  onError,
}: PasskeyButtonProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const supported = typeof window !== 'undefined' && 'PublicKeyCredential' in window;

  async function post(url: string, body?: unknown): Promise<any> {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
      },
      body: JSON.stringify(body ?? {}),
    });
    if (!response.ok) {
      throw new Error('The passkey could not be verified. Please try again.');
    }
    return response.json();
  }

  async function handleClick() {
    setBusy(true);
    setError(null);

    try {
      const options = await post(optionsUrl);
      options.challenge = toBuffer(options.challenge);

      let credential: PublicKeyCredential | null;
      if (mode === 'register') {
        options.user.id = toBuffer(options.user.id);
        options.excludeCredentials = (options.excludeCredentials ?? []).map((item: any) => ({
          ...item,
          id: toBuffer(item.id),
        }));
        credential = (await navigator.credentials.create({ publicKey: options })) as PublicKeyCredential | null;
      } else {
        options.allowCredentials = (options.allowCredentials ?? []).map((item: any) => ({
          ...item,
          id: toBuffer(item.id),
        }));
        credential = (await navigator.credentials.get({ publicKey: options })) as PublicKeyCredential | null;
      }

      if (!credential) {
        throw new Error('No passkey was selected.');
      }

      const response = credential.response as any;
      const result = await post(verifyUrl, {
        id: credential.id,
        rawId: toBase64Url(credential.rawId),
        type: credential.type,
        response: {
          clientDataJSON: toBase64Url(response.clientDataJSON),
          attestationObject: toBase64Url(response.attestationObject ?? null),
          authenticatorData: toBase64Url(response.authenticatorData ?? null),
          signature: toBase64Url(response.signature ?? null),
          userHandle: toBase64Url(response.userHandle ?? null),
          transports: typeof response.getTransports === 'function' ? response.getTransports() : [],
        },
      });

      onSuccess?.(result);
    } catch (caught) {
      // NotAllowedError = the user dismissed the browser prompt: not worth an error message.
      const dismissed = caught instanceof DOMException && caught.name === 'NotAllowedError';
      const message = caught instanceof Error && !dismissed ? caught.message : null;
      setError(message);
      if (message) {
        onError?.(message);
      }
    } finally {
      setBusy(false);
    }
  }

  if (!supported) {
    return null;
  }

  return (
    <div>
      <button
        type="button"
        onClick={handleClick}
        disabled={busy}
        className="flex w-full items-center justify-center gap-2.5 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-700 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-60"
      >
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          className="h-5 w-5"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z" />
        </svg>
        <span>
          {busy
            ? 'Waiting for your device…'
            : (label ?? (mode === 'register' ? 'Add a passkey' : 'Sign in with a passkey'))}
        </span>
      </button>
      {error && (
        <p role="alert" className="mt-2 text-center text-sm text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}

export default PasskeyButton;
