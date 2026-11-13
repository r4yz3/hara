import Link from "next/link";
import type { ReactNode } from "react";

import { describeFailure, type ApiFailure } from "@food-delivery/session";

// Small shared pieces for the console screens. Colours are approved pairs
// from docs/ux/contrast-report.md.

export const fieldClass =
  "min-h-11 w-full rounded-xl border border-border-strong bg-surface px-3 text-base text-text-primary";
export const primaryButton =
  "min-h-11 rounded-xl bg-brand-600 px-4 font-semibold text-on-brand disabled:opacity-70";
export const quietButton =
  "min-h-11 rounded-xl border border-border-strong bg-surface px-4 font-medium text-text-primary disabled:opacity-70";
export const dangerButton =
  "min-h-11 rounded-xl bg-danger px-4 font-semibold text-on-danger disabled:opacity-70";

/** Status as a word in a pill. Never colour alone: the word carries it. */
export function StatusPill({ status }: { status: string }) {
  const live = status === "active";

  return (
    <span
      className={`inline-flex min-h-7 items-center rounded-full border px-3 text-sm font-medium ${
        live ? "border-success text-success" : "border-border-strong text-text-secondary"
      }`}
    >
      {live ? "● " : "○ "}
      {status.charAt(0).toUpperCase() + status.slice(1)}
    </span>
  );
}

export function Panel({ children, dashed = false }: { children: ReactNode; dashed?: boolean }) {
  return (
    <div
      className={`flex flex-col gap-3 rounded-2xl border bg-surface p-4 sm:p-5 ${
        dashed ? "border-dashed border-border-strong" : "border-border"
      }`}
    >
      {children}
    </div>
  );
}

/** What to show when a load fails: says what happened and offers the next step. */
export function LoadProblem({ failure, returnTo, onRetry }: { failure: ApiFailure; returnTo: string; onRetry: () => void }) {
  return (
    <Panel>
      <p role="alert" className="font-medium">
        {describeFailure(failure)}
      </p>
      <div className="flex flex-wrap gap-2">
        {failure.kind === "signed_out" ? (
          <Link href={`/login?next=${encodeURIComponent(returnTo)}`} className={`${primaryButton} content-center`}>
            Sign in
          </Link>
        ) : failure.kind === "not_found" || failure.kind === "forbidden" ? (
          <Link href="/" className={`${quietButton} content-center`}>
            Back to overview
          </Link>
        ) : (
          <button type="button" onClick={onRetry} className={quietButton}>
            Try again
          </button>
        )}
      </div>
    </Panel>
  );
}

/**
 * Moves focus to the first field the server rejected (spec 8.7, validation).
 * `order` lists API field names with the selector of their input, in the
 * order they appear on screen; a nested name such as `geometry.0` counts
 * for its parent.
 */
export function focusFirstInvalid(
  order: [field: string, selector: string][],
  fields: Record<string, string[]>,
  root: ParentNode = document,
) {
  const names = Object.keys(fields);
  const hit = order.find(([field]) => names.some((name) => name === field || name.startsWith(`${field}.`)));
  if (hit) root.querySelector<HTMLElement>(hit[1])?.focus();
}

export function FieldError({ id, messages }: { id: string; messages?: string[] }) {
  if (!messages || messages.length === 0) return null;

  return (
    <p id={id} className="text-sm font-medium text-danger">
      {messages.join(" ")}
    </p>
  );
}

export function Notice({ tone, children }: { tone: "ok" | "problem"; children: ReactNode }) {
  return (
    <p
      role={tone === "problem" ? "alert" : "status"}
      className={`text-sm font-medium ${tone === "problem" ? "text-danger" : "text-success"}`}
    >
      {children}
    </p>
  );
}
