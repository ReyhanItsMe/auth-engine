import type { ReactNode } from 'react';

export interface AuthCardProps {
  title: string;
  subtitle?: string;
  /** Small logo or icon shown above the title. */
  logo?: ReactNode;
  /** Text and links shown under the card, e.g. "No account? Sign up". */
  footer?: ReactNode;
  children: ReactNode;
}

export function AuthCard({ title, subtitle, logo, footer, children }: AuthCardProps) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-12">
      <div className="w-full max-w-md">
        <div className="rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-900/5 sm:p-10">
          {logo && <div className="mb-6 flex justify-center">{logo}</div>}
          <div className="mb-8 text-center">
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
            {subtitle && <p className="mt-2 text-sm text-slate-500">{subtitle}</p>}
          </div>
          <div className="space-y-6">{children}</div>
        </div>
        {footer && <div className="mt-6 text-center text-sm text-slate-500">{footer}</div>}
      </div>
    </div>
  );
}

export default AuthCard;
