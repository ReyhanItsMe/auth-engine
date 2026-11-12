import { useState } from 'react';
import { AuthCard } from './AuthCard';
import { CredentialForm } from './CredentialForm';
import { PasskeyButton } from './PasskeyButton';
import { SocialLoginRow } from './SocialLoginRow';
/**
 * Example sign-in page wiring the components together.
 * Adjust the endpoints to your routes; each one maps to a single Auth:: call.
 */
export function LoginExample({ providers = [], csrfToken }) {
  const [error, setError] = useState();
  async function signIn(values) {
    setError(undefined);
    const response = await fetch('/auth/login', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
      },
      body: JSON.stringify({ identifier: values.identifier, password: values.password }),
    });
    // Expected body: AuthResult::toArray() -> { status, reason, retry_after, mfa_methods }
    const result = await response.json();
    if (result.status === 'success') {
      window.location.assign('/dashboard');
    } else if (result.status === 'mfa_required') {
      window.location.assign('/auth/mfa');
    } else if (result.status === 'rate_limited') {
      setError(`Too many attempts. Try again in ${result.retry_after} seconds.`);
    } else {
      setError('These credentials do not match our records.');
    }
  }
  return (
    <AuthCard
      title="Welcome back"
      subtitle="Sign in to your account to continue"
      footer={
        <>
          No account yet?{' '}
          <a href="/register" className="font-medium text-indigo-600 transition hover:text-indigo-500">
            Create one
          </a>
        </>
      }
    >
      <CredentialForm mode="login" errors={{ form: error }} forgotPasswordHref="/forgot-password" onSubmit={signIn} />
      <PasskeyButton
        mode="login"
        optionsUrl="/auth/passkey/options"
        verifyUrl="/auth/passkey/verify"
        csrfToken={csrfToken}
        onSuccess={() => window.location.assign('/dashboard')}
      />
      <SocialLoginRow providers={providers} />
    </AuthCard>
  );
}
export default LoginExample;
