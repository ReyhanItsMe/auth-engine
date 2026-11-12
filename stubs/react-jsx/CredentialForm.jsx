import { useState } from 'react';
const inputClass =
  'block w-full rounded-lg border-0 px-3.5 py-2.5 text-sm text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 ' +
  'placeholder:text-slate-400 transition focus:ring-2 focus:ring-inset focus:ring-indigo-600';
export function CredentialForm({
  mode = 'login',
  identifierLabel = 'Email',
  identifierType = 'email',
  errors = {},
  forgotPasswordHref,
  minPasswordLength = 8,
  onSubmit,
}) {
  const [values, setValues] = useState({ identifier: '', password: '', name: '' });
  const [busy, setBusy] = useState(false);
  const isRegister = mode === 'register';
  const set = (field) => (event) => setValues((current) => ({ ...current, [field]: event.target.value }));
  async function handleSubmit(event) {
    event.preventDefault();
    setBusy(true);
    try {
      await onSubmit(values);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      {errors.form && (
        <div
          role="alert"
          className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-inset ring-red-200"
        >
          {errors.form}
        </div>
      )}

      {isRegister && (
        <div>
          <label htmlFor="auth-name" className="mb-1.5 block text-sm font-medium text-slate-700">
            Name
          </label>
          <input
            id="auth-name"
            type="text"
            autoComplete="name"
            value={values.name}
            onChange={set('name')}
            className={inputClass}
          />
          {errors.name && <p className="mt-1.5 text-sm text-red-600">{errors.name}</p>}
        </div>
      )}

      <div>
        <label htmlFor="auth-identifier" className="mb-1.5 block text-sm font-medium text-slate-700">
          {identifierLabel}
        </label>
        <input
          id="auth-identifier"
          type={identifierType}
          required
          autoComplete="username"
          value={values.identifier}
          onChange={set('identifier')}
          aria-invalid={errors.identifier ? true : undefined}
          className={inputClass}
        />
        {errors.identifier && <p className="mt-1.5 text-sm text-red-600">{errors.identifier}</p>}
      </div>

      <div>
        <div className="mb-1.5 flex items-center justify-between">
          <label htmlFor="auth-password" className="block text-sm font-medium text-slate-700">
            Password
          </label>
          {!isRegister && forgotPasswordHref && (
            <a
              href={forgotPasswordHref}
              className="text-sm font-medium text-indigo-600 transition hover:text-indigo-500"
            >
              Forgot password?
            </a>
          )}
        </div>
        <input
          id="auth-password"
          type="password"
          required
          minLength={isRegister ? minPasswordLength : undefined}
          autoComplete={isRegister ? 'new-password' : 'current-password'}
          value={values.password}
          onChange={set('password')}
          aria-invalid={errors.password ? true : undefined}
          className={inputClass}
        />
        {errors.password && <p className="mt-1.5 text-sm text-red-600">{errors.password}</p>}
      </div>

      <button
        type="submit"
        disabled={busy}
        className="flex w-full justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {busy ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in'}
      </button>
    </form>
  );
}
export default CredentialForm;
