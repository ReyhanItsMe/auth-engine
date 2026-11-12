import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

export interface MfaSetupFormProps {
  /** "secret" from Auth::totp()->beginSetup($user): shown for manual entry. */
  secret: string;
  /** "uri" (otpauth://...) from beginSetup(): encode it as a QR code. */
  uri: string;
  /**
   * Renders the QR code. Keep it client-side so the secret never leaves the page, e.g.
   *   import { QRCodeSVG } from 'qrcode.react';
   *   renderQr={(uri) => <QRCodeSVG value={uri} size={176} />}
   */
  renderQr?: (uri: string) => ReactNode;
  /** Recovery codes returned by Auth::totp()->confirm(); when set, they are shown instead of the form. */
  recoveryCodes?: string[];
  error?: string;
  digits?: number;
  onSubmit: (code: string) => void | Promise<void>;
}

export function MfaSetupForm({ secret, uri, renderQr, recoveryCodes, error, digits = 6, onSubmit }: MfaSetupFormProps) {
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);

  if (recoveryCodes && recoveryCodes.length > 0) {
    return (
      <div className="space-y-4">
        <div className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 ring-1 ring-inset ring-emerald-200">
          Two-factor authentication is on. Save these recovery codes somewhere safe — each one works once and they will
          not be shown again.
        </div>
        <ul className="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-sm text-slate-800 ring-1 ring-inset ring-slate-200">
          {recoveryCodes.map((recoveryCode) => (
            <li key={recoveryCode}>{recoveryCode}</li>
          ))}
        </ul>
      </div>
    );
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    try {
      await onSubmit(code);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <ol className="list-inside list-decimal space-y-1 text-sm text-slate-600">
        <li>Open your authenticator app (Google Authenticator, Authy, 1Password…).</li>
        <li>Scan the QR code or enter the setup key.</li>
        <li>Type the {digits}-digit code the app shows.</li>
      </ol>

      <div className="flex flex-col items-center gap-4">
        {renderQr && <div className="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-900/10">{renderQr(uri)}</div>}
        <div className="w-full">
          <p className="mb-1.5 text-xs font-medium uppercase tracking-wide text-slate-400">Setup key</p>
          <code className="block select-all break-all rounded-lg bg-slate-50 px-3 py-2 text-center font-mono text-sm tracking-widest text-slate-800 ring-1 ring-inset ring-slate-200">
            {secret}
          </code>
          <a
            href={uri}
            className="mt-2 block text-center text-sm font-medium text-indigo-600 transition hover:text-indigo-500"
          >
            Open in authenticator app
          </a>
        </div>
      </div>

      <div>
        <label htmlFor="auth-totp-code" className="mb-1.5 block text-sm font-medium text-slate-700">
          Verification code
        </label>
        <input
          id="auth-totp-code"
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          pattern="[0-9 ]*"
          maxLength={digits + 1}
          required
          value={code}
          onChange={(event) => setCode(event.target.value)}
          aria-invalid={error ? true : undefined}
          className="block w-full rounded-lg border-0 px-3.5 py-2.5 text-center font-mono text-lg tracking-[0.4em] text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600"
        />
        {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
      </div>

      <button
        type="submit"
        disabled={busy}
        className="flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {busy ? 'Verifying…' : 'Turn on two-factor authentication'}
      </button>
    </form>
  );
}

export default MfaSetupForm;
