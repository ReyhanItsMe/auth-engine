export function SocialLoginRow({ providers, dividerLabel = 'Or continue with' }) {
  if (providers.length === 0) {
    return null;
  }
  return (
    <div className="space-y-5">
      {dividerLabel && (
        <div className="relative flex items-center">
          <div className="grow border-t border-slate-200" />
          <span className="mx-4 shrink-0 text-xs font-medium uppercase tracking-wide text-slate-400">
            {dividerLabel}
          </span>
          <div className="grow border-t border-slate-200" />
        </div>
      )}

      <div className={providers.length === 1 ? 'grid gap-3' : 'grid grid-cols-2 gap-3'}>
        {providers.map((provider) => (
          <a
            key={provider.key}
            href={provider.url}
            className="flex items-center justify-center gap-2.5 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 transition hover:bg-slate-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
          >
            <span
              aria-hidden="true"
              className="h-5 w-5 shrink-0 [&>svg]:h-full [&>svg]:w-full [&>svg]:fill-current"
              style={{ color: provider.color }}
              dangerouslySetInnerHTML={{ __html: provider.icon }}
            />
            <span>{provider.name}</span>
          </a>
        ))}
      </div>
    </div>
  );
}
export default SocialLoginRow;
