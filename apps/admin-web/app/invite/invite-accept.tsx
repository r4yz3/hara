"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";

import type { InvitationPreview } from "@food-delivery/api-client";
import { apiSend, describeFailure } from "@food-delivery/session";

import { primaryButton, quietButton } from "@/components/ui";

type Envelope<T> = { data: T };

type State =
  | { phase: "reading" }
  | { phase: "missing" }
  | { phase: "inspecting" }
  | { phase: "invalid" }
  | { phase: "problem"; text: string }
  | { phase: "ready"; preview: InvitationPreview }
  | { phase: "done" };

const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";
const invalidText =
  "This invitation link is not valid. It may have expired, been used already, or been withdrawn. Ask the person who invited you to send a new one.";

function formatWhen(iso: string): string {
  const date = new Date(iso);

  return Number.isNaN(date.getTime()) ? iso : `${date.toISOString().slice(0, 16).replace("T", " ")} UTC`;
}

/**
 * Opens an emailed staff invitation. The token lives in the URL fragment,
 * which browsers never send to a server. It is read once, removed from the
 * address bar at once, and kept only in this component's memory: never in a
 * query string, storage, a log or analytics.
 */
export function InviteAccept() {
  const [state, setState] = useState<State>({ phase: "reading" });
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [show, setShow] = useState(false);
  const token = useRef<string | null>(null);
  const read = useRef(false);
  const passwordRef = useRef<HTMLInputElement>(null);

  const inspect = useCallback(async () => {
    if (token.current === null) return;

    setState({ phase: "inspecting" });
    const result = await apiSend<Envelope<InvitationPreview>>("POST", "/api/v1/auth/invitations/inspect", { token: token.current });

    if (result.ok) setState({ phase: "ready", preview: result.value.data });
    else if (result.failure.kind === "not_found" || result.failure.kind === "validation") setState({ phase: "invalid" });
    else if (result.failure.kind === "rate_limited") setState({ phase: "problem", text: "Too many attempts with this link. Wait a few minutes and try again." });
    else setState({ phase: "problem", text: describeFailure(result.failure) });
  }, []);

  useEffect(() => {
    // Exactly once: the first read clears the address bar, so React's repeated
    // development effect would find nothing and wrongly report a missing link.
    if (read.current) return;
    read.current = true;

    const found = new URLSearchParams(window.location.hash.replace(/^#/, "")).get("token");
    window.history.replaceState(null, "", window.location.pathname);

    if (found && /^[0-9a-f]{40,200}$/.test(found)) {
      token.current = found;
      void inspect();
    } else {
      // Reading the browser's address is the reason this effect exists.
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setState({ phase: "missing" });
    }
  }, [inspect]);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || token.current === null) return;

    const data = new FormData(event.currentTarget);
    const password = String(data.get("password") ?? "");
    const again = String(data.get("password_again") ?? "");

    if (password.length < 10) {
      setProblem("Enter a password of at least 10 characters.");
      passwordRef.current?.focus();
      return;
    }
    if (password !== again) {
      setProblem("The two passwords do not match.");
      return;
    }

    setBusy(true);
    setProblem(null);
    const result = await apiSend<unknown>("POST", "/api/v1/auth/invitations/accept", { token: token.current, password });
    setBusy(false);

    if (result.ok) {
      token.current = null;
      setState({ phase: "done" });
      return;
    }

    if (result.failure.kind === "not_found") setProblem(invalidText);
    else if (result.failure.kind === "validation") setProblem(Object.values(result.failure.fields).flat().join(" ") || describeFailure(result.failure));
    else if (result.failure.kind === "rate_limited") setProblem("Too many attempts with this link. Wait a few minutes and try again.");
    else setProblem(describeFailure(result.failure));
  }

  switch (state.phase) {
    case "reading":
    case "inspecting":
      return (
        <p role="status" aria-busy="true" className="text-text-secondary">
          Checking your invitation…
        </p>
      );

    case "missing":
      return (
        <p role="alert" className="rounded-xl border border-border-strong p-4">
          This page needs the link from an invitation email. Open the link from the email again.
        </p>
      );

    case "invalid":
      return (
        <p role="alert" className="rounded-xl border border-border-strong p-4">
          {invalidText}
        </p>
      );

    case "problem":
      return (
        <div className="flex flex-col gap-3">
          <p role="alert" className="rounded-xl border border-border-strong p-4">
            {state.text}
          </p>
          <button type="button" onClick={() => void inspect()} className={quietButton}>
            Try again
          </button>
        </div>
      );

    case "done":
      return (
        <div className="flex flex-col gap-3">
          <p role="status" className="rounded-xl border border-border-strong p-4">
            Invitation accepted. Sign in, then set up two-step sign-in.
          </p>
          <Link href="/login" className={`${primaryButton} content-center text-center`}>
            Go to sign in
          </Link>
        </div>
      );

    case "ready":
      return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          <dl className="flex flex-col gap-2 rounded-xl border border-border bg-surface-2 p-4 text-sm">
            <div>
              <dt className="text-text-secondary">Invited as</dt>
              <dd className="font-medium">{state.preview.name}</dd>
            </div>
            <div>
              <dt className="text-text-secondary">Role</dt>
              <dd className="font-medium">{state.preview.role_label}</dd>
            </div>
            <div>
              <dt className="text-text-secondary">Applies to</dt>
              <dd className="font-medium">{state.preview.scope_label}</dd>
            </div>
            <div>
              <dt className="text-text-secondary">Link expires</dt>
              <dd className="font-medium">
                <time dateTime={state.preview.expires_at}>{formatWhen(state.preview.expires_at)}</time>
              </dd>
            </div>
          </dl>

          <p className="text-sm text-text-secondary">
            If you already have an account with this address, enter its current password; it will not be changed. Otherwise choose a new password.
          </p>

          <div className="flex flex-col gap-1.5">
            <label htmlFor="invite-password" className="text-sm font-medium">
              Password
            </label>
            <input
              ref={passwordRef}
              id="invite-password"
              name="password"
              type={show ? "text" : "password"}
              autoComplete="new-password"
              required
              aria-describedby="invite-password-hint"
              className={field}
            />
            <p id="invite-password-hint" className="text-sm text-text-secondary">
              At least 10 characters.
            </p>
          </div>
          <div className="flex flex-col gap-1.5">
            <label htmlFor="invite-password-again" className="text-sm font-medium">
              Password again
            </label>
            <input
              id="invite-password-again"
              name="password_again"
              type={show ? "text" : "password"}
              autoComplete="new-password"
              required
              className={field}
            />
          </div>
          <label className="flex min-h-11 items-center gap-2 text-sm font-medium">
            <input type="checkbox" checked={show} onChange={(event) => setShow(event.target.checked)} className="size-5" />
            Show passwords
          </label>

          <p role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
            {problem}
          </p>
          <button type="submit" disabled={busy} aria-busy={busy} className={`${primaryButton} min-h-12 text-base`}>
            {busy ? "Accepting…" : "Accept invitation"}
          </button>
        </form>
      );
  }
}
