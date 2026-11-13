"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";

import { apiSend, describeFailure } from "./api";

const field = "min-h-12 w-full rounded-xl border border-border-strong bg-surface px-4 text-base text-text-primary";
const button = "min-h-12 rounded-xl px-4 text-base font-semibold disabled:opacity-70";

/**
 * Asks for a reset link. The answer is the same whether or not the address
 * has an account, so the screen says the same thing either way.
 */
export function ForgotPasswordForm({ actionClassName }: { actionClassName: string }) {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const emailRef = useRef<HTMLInputElement>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;

    const email = String(new FormData(event.currentTarget).get("email") ?? "").trim();
    if (email === "") {
      setProblem("Enter your email.");
      emailRef.current?.focus();
      return;
    }

    setBusy(true);
    setProblem(null);
    const result = await apiSend<unknown>("POST", "/api/v1/auth/password/forgot", { email });
    setBusy(false);

    if (result.ok) setSent(true);
    else setProblem(result.failure.kind === "validation" ? "Enter your email." : describeFailure(result.failure));
  }

  if (sent) {
    return (
      <p role="status" className="rounded-xl border border-border-strong p-4">
        If that address has an account, we have sent it a link to choose a new password. The link works once and
        expires in an hour.
      </p>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="forgot-email" className="text-sm font-medium">
          Email
        </label>
        <input ref={emailRef} id="forgot-email" name="email" type="email" autoComplete="username" inputMode="email" required className={field} />
      </div>
      <p role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
        {problem}
      </p>
      <button type="submit" disabled={busy} aria-busy={busy} className={`${button} ${actionClassName}`}>
        {busy ? "Sending…" : "Send reset link"}
      </button>
    </form>
  );
}

/**
 * Sets a new password using the token from the emailed link. The token is
 * read from the URL fragment, which browsers never send to a server, and is
 * removed from the address bar as soon as it has been read.
 */
export function ResetPasswordForm({ actionClassName, signInHref = "/login" }: { actionClassName: string; signInHref?: string }) {
  const [token, setToken] = useState<string | null | undefined>(undefined);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const passwordRef = useRef<HTMLInputElement>(null);

  const tokenRead = useRef(false);

  useEffect(() => {
    // Exactly once: the first read clears the address bar, so a second run
    // (React repeats effects in development) would find nothing and wrongly
    // report a missing link.
    if (tokenRead.current) return;
    tokenRead.current = true;

    const found = new URLSearchParams(window.location.hash.replace(/^#/, "")).get("token");
    window.history.replaceState(null, "", window.location.pathname);
    // Reading the browser's address is the reason this effect exists.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setToken(found && /^[0-9a-f]{40,200}$/.test(found) ? found : null);
  }, []);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || !token) return;

    const data = new FormData(event.currentTarget);
    const password = String(data.get("password") ?? "");
    const again = String(data.get("password_again") ?? "");

    if (password.length < 10) {
      setProblem("Choose a password of at least 10 characters.");
      passwordRef.current?.focus();
      return;
    }
    if (password !== again) {
      setProblem("The two passwords do not match.");
      return;
    }

    setBusy(true);
    setProblem(null);
    const result = await apiSend<unknown>("POST", "/api/v1/auth/password/reset", { token, password });
    setBusy(false);

    if (result.ok) {
      setDone(true);
      return;
    }

    setProblem(
      result.failure.kind === "validation"
        ? Object.values(result.failure.fields).flat().join(" ") || describeFailure(result.failure)
        : describeFailure(result.failure),
    );
  }

  if (token === undefined) {
    return (
      <p aria-busy="true" className="text-text-secondary">
        Checking your link…
      </p>
    );
  }

  if (token === null) {
    return (
      <p role="alert" className="rounded-xl border border-border-strong p-4">
        This page needs the link from a password reset email. Request a new link and open it from the email.
      </p>
    );
  }

  if (done) {
    return (
      <div className="flex flex-col gap-3">
        <p role="status" className="rounded-xl border border-border-strong p-4">
          Your password has been changed. Sign in with the new one.
        </p>
        <a href={signInHref} className={`${button} content-center text-center ${actionClassName}`}>
          Go to sign in
        </a>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      <div className="flex flex-col gap-1.5">
        <label htmlFor="new-password" className="text-sm font-medium">
          New password
        </label>
        <input
          ref={passwordRef}
          id="new-password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
          aria-describedby="new-password-hint"
          className={field}
        />
        <p id="new-password-hint" className="text-sm text-text-secondary">
          At least 10 characters.
        </p>
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor="new-password-again" className="text-sm font-medium">
          New password again
        </label>
        <input id="new-password-again" name="password_again" type="password" autoComplete="new-password" required className={field} />
      </div>
      <p role="alert" aria-live="assertive" className="min-h-6 text-sm font-medium text-danger">
        {problem}
      </p>
      <button type="submit" disabled={busy} aria-busy={busy} className={`${button} ${actionClassName}`}>
        {busy ? "Saving…" : "Set new password"}
      </button>
    </form>
  );
}
