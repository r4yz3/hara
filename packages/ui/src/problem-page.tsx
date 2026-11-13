"use client";

import type { ReactNode } from "react";

// Full-page problem screens shared by the three web apps (backlog U-03):
// an unexpected error, a failure of the root layout, and an unknown address.
//
// Rules: plain words, a way forward (try again, home), and nothing from the
// server or the stack. The only technical detail ever shown is a short
// reference id taken from the framework's digest, so staff can match the
// report to a server log. The error's message is never read here.
//
// Colours are approved pairs from docs/ux/contrast-report.md: text-primary and
// text-secondary on surface, on-brand on brand-600, border-strong on surface.

const primaryAction =
  "inline-flex min-h-11 items-center justify-center rounded-xl bg-brand-600 px-4 font-semibold text-on-brand";
const quietAction =
  "inline-flex min-h-11 items-center justify-center rounded-xl border border-border-strong bg-surface px-4 font-medium text-text-primary";

/** A short, safe reference from an error's digest, or undefined when there is none. */
export function referenceOf(error: unknown): string | undefined {
  if (typeof error !== "object" || error === null || !("digest" in error)) return undefined;
  const digest = (error as { digest?: unknown }).digest;
  if (typeof digest !== "string") return undefined;
  const short = digest.slice(0, 8);
  return /^[A-Za-z0-9]{1,8}$/.test(short) ? short : undefined;
}

function ProblemPage({
  title,
  alert,
  children,
  actions,
}: {
  title: string;
  alert?: boolean;
  children: ReactNode;
  actions: ReactNode;
}) {
  return (
    <main className="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-4 py-10">
      <div className="flex flex-col gap-3 rounded-2xl border border-border bg-surface p-6 text-text-primary">
        <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
        <div role={alert ? "alert" : undefined} className="flex flex-col gap-2">
          {children}
        </div>
        <div className="flex flex-wrap gap-2 pt-2">{actions}</div>
      </div>
    </main>
  );
}

/**
 * Shown when something unexpected breaks. Use it from `error.tsx` and
 * `global-error.tsx`; pass the `error` and `retry` props through unchanged.
 */
export function ErrorScreen({ error, retry }: { error: unknown; retry: () => void }) {
  const reference = referenceOf(error);

  return (
    <ProblemPage
      title="Something went wrong"
      alert
      actions={
        <>
          <button type="button" onClick={() => retry()} className={primaryAction}>
            Try again
          </button>
          {/* A full page load, so it also works when the app itself is broken. */}
          <a href="/" className={quietAction}>
            Go to the home page
          </a>
        </>
      }
    >
      <p>This page could not be shown. Nothing you entered has been saved. Try again, or go back to the home page.</p>
      {reference ? <p className="text-sm text-text-secondary">Reference: {reference}</p> : null}
    </ProblemPage>
  );
}

/** Shown for an address that does not exist. Use it from `not-found.tsx`. */
export function NotFoundScreen() {
  return (
    <ProblemPage
      title="Page not found"
      actions={
        <a href="/" className={primaryAction}>
          Go to the home page
        </a>
      }
    >
      <p>There is no page at this address. It may have moved, or the address may have a typo.</p>
    </ProblemPage>
  );
}
